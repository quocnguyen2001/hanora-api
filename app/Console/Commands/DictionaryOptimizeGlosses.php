<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\OptimizeWordGlosses;
use App\Models\DictionaryWord;
use App\Services\Dictionary\Glosses\GlossPrompt;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dọn nghĩa tiếng Việt của từ điển bằng AI.
 *
 * Ghi vào cột `*_vi_ai*`; `definitions_vi` của CVDICT KHÔNG bị đụng tới. Hỏng thì
 * xoá cột AI là quay về đúng hành vi cũ, không mất gì.
 *
 * Chỉ XẾP HÀNG, không gọi Gemini trực tiếp — cùng lý do với `dictionary:enrich`:
 * throttle, retry và ghi database đã nằm trong job, và một đường thứ hai là một
 * đường có thể lệch hành vi.
 */
final class DictionaryOptimizeGlosses extends Command
{
    protected $signature = 'dictionary:optimize-glosses
        {--all : Mọi mục có nghĩa tiếng Việt}
        {--frequency= : Chỉ những từ có frequency_rank nhỏ hơn giá trị này}
        {--hsk : Chỉ những từ có hsk_level}
        {--stale : Những mục đã dọn bằng phiên bản prompt cũ}
        {--limit= : Trần số TỪ xếp hàng}
        {--force : Dọn lại cả những mục đã có nghĩa AI}
        {--pretend : Chỉ đếm và in kế hoạch, không xếp job}';

    protected $description = 'Dọn và sắp lại nghĩa tiếng Việt bằng AI, ghi vào cột riêng cho tìm kiếm';

    private const CHUNK = 500;

    public function handle(): int
    {
        /*
         * Chỉ đụng những mục CÓ nghĩa tiếng Việt. Prompt dọn thứ đã có, nên mục
         * trống không có gì để dọn — 8.606 mục thuộc nhóm này và xếp chúng vào
         * hàng đợi là trả tiền cho một lô rỗng.
         */
        $query = DictionaryWord::query()->whereNotNull('definitions_vi');

        if ($this->option('hsk')) {
            $query->whereNotNull('hsk_level');
        }

        $frequency = $this->option('frequency');

        if (is_numeric($frequency)) {
            $query->where('frequency_rank', '<', (int) $frequency);
        }

        if ($this->option('stale')) {
            $query->where('vi_ai_version', '<', GlossPrompt::VERSION);
        } elseif (! $this->option('force')) {
            // Bỏ qua mục đã dọn ở phiên bản hiện hành — đây là thứ khiến lệnh
            // chạy lại được sau khi bị ngắt giữa chừng.
            $query->where(fn (Builder $q) => $q
                ->whereNull('vi_ai_version')
                ->orWhere('vi_ai_version', '<', GlossPrompt::VERSION));
        }

        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;

        $total = $query->clone()->count();
        $total = $limit !== null ? min($limit, $total) : $total;

        if ($total === 0) {
            $this->info('Không có mục nào cần dọn.');

            return self::SUCCESS;
        }

        $batches = (int) ceil($total / GlossPrompt::BATCH);

        // Số đo 2026-08-28 cho lời gọi diễn giải; lô 20 từ tốn nhiều token hơn
        // nhưng chia cho 20 nên chi phí mỗi từ thấp hơn hẳn gọi lẻ.
        $this->table(
            ['Số từ', 'Số lô', 'Kích thước lô', 'Ước chi phí (USD)'],
            [[$total, $batches, GlossPrompt::BATCH, number_format($batches * 0.0018, 2)]],
        );

        if ($this->option('pretend')) {
            $this->comment('--pretend: không xếp job nào.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $buffer = [];
        $queued = 0;

        $query->orderBy('id')->chunkById(self::CHUNK, function ($words) use (&$buffer, &$queued, $bar, $limit): bool {
            foreach ($words as $word) {
                if ($limit !== null && $queued >= $limit) {
                    break;
                }

                $buffer[] = $word->id;
                $queued++;
                $bar->advance();

                if (count($buffer) >= GlossPrompt::BATCH) {
                    OptimizeWordGlosses::dispatch($buffer)->onQueue('glosses');
                    $buffer = [];
                }
            }

            return ! ($limit !== null && $queued >= $limit);
        });

        // Lô cuối gần như luôn không đầy; bỏ nó là bỏ tới 19 từ mỗi lần chạy.
        if ($buffer !== []) {
            OptimizeWordGlosses::dispatch($buffer)->onQueue('glosses');
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã xếp {$queued} từ. Chạy worker: php artisan queue:work --queue=glosses");

        return self::SUCCESS;
    }
}
