<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateWordEnrichment;
use App\Models\DictionaryWord;
use App\Models\DictionaryWordEnrichment;
use App\Services\Dictionary\Enrichment\EnrichmentPrompt;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Nạp sẵn nội dung làm giàu cho một tập từ.
 *
 * Command này chỉ **xếp hàng**, không gọi Gemini trực tiếp. Toàn bộ logic
 * throttle, retry, validate và ghi database đã nằm trong `GenerateWordEnrichment`;
 * gọi thẳng ở đây là nhân đôi logic và tạo ra một đường thứ hai có thể lệch hành
 * vi với đường mà người dùng thật đi qua.
 */
final class DictionaryEnrich extends Command
{
    protected $signature = 'dictionary:enrich
        {--hsk : Chỉ những từ có hsk_level}
        {--priority : Chỉ những từ is_priority}
        {--stale : Những bản ghi sinh bằng prompt cũ}
        {--limit= : Trần số từ xếp hàng}
        {--force : Xếp cả những từ đã ready}';

    protected $description = 'Xếp hàng sinh nội dung làm giàu cho từ điển';

    /** Nạp theo lô, không `get()` cả 123.646 model vào bộ nhớ. */
    private const CHUNK = 200;

    public function handle(): int
    {
        $query = DictionaryWord::query();

        if ($this->option('hsk')) {
            $query->whereNotNull('hsk_level');
        }

        if ($this->option('priority')) {
            $query->where('is_priority', true);
        }

        if ($this->option('stale')) {
            /*
             * Bản ghi sinh bằng prompt cũ. Chỉ có nghĩa cùng `--force`, vì nếu
             * không thì bộ lọc "chưa ready" bên dưới đã loại chúng ra rồi.
             */
            $query->whereHas('enrichment', fn (Builder $q) => $q
                ->where('status', DictionaryWordEnrichment::STATUS_READY)
                ->where('prompt_version', '<', EnrichmentPrompt::VERSION));
        } elseif (! $this->option('force')) {
            /*
             * Bỏ qua từ đã có nội dung dùng được. Đây là thứ khiến command chạy
             * lại được: đợt pre-warm bị ngắt giữa chừng thì chạy lại chỉ xếp
             * phần còn thiếu, không trả tiền lần hai cho phần đã xong.
             *
             * Bản ghi `failed` KHÔNG bị loại ở đây — job tự dừng khi cạn lượt,
             * và đó là chỗ đúng để quyết định, không phải ở đây.
             */
            $query->whereDoesntHave('enrichment', fn (Builder $q) => $q
                ->where('status', DictionaryWordEnrichment::STATUS_READY)
                ->where('prompt_version', EnrichmentPrompt::VERSION));
        }

        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;

        $total = $limit !== null ? min($limit, $query->clone()->count()) : $query->clone()->count();

        if ($total === 0) {
            $this->info('Không có từ nào cần sinh.');

            return self::SUCCESS;
        }

        $this->info("Xếp hàng {$total} từ trên queue `enrichment`.");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $queued = 0;

        $query->orderBy('id')->chunkById(self::CHUNK, function ($words) use (&$queued, $bar, $limit): bool {
            foreach ($words as $word) {
                if ($limit !== null && $queued >= $limit) {
                    return false;
                }

                /*
                 * Queue riêng `enrichment`, KHÔNG dùng chung `default`. Một đợt
                 * pre-warm 4.987 từ trên hàng đợi chung sẽ bắt người đang mở màn
                 * chi tiết xếp sau toàn bộ số đó.
                 */
                GenerateWordEnrichment::dispatch($word->id)->onQueue('enrichment');
                $queued++;
                $bar->advance();
            }

            return true;
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Đã xếp {$queued} job. Chạy worker: php artisan queue:work --queue=enrichment");

        return self::SUCCESS;
    }
}
