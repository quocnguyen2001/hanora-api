<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Topic\TopicCatalog;
use App\Services\Topic\TopicGenerationOutcome;
use App\Services\Topic\TopicGenerationResult;
use App\Services\Topic\TopicGenerator;
use App\Services\Topic\TopicPrompt;
use Illuminate\Console\Command;

/**
 * Sinh bộ từ chủ đề bằng Gemini và ghi ra `database/data/topics/{slug}.json`.
 *
 * Lệnh DUY NHẤT trong cả tính năng có gọi AI, và nó chạy ở máy maintainer —
 * production không bao giờ chạm Gemini vì nội dung đã nằm sẵn trong bảng.
 *
 * KHÔNG ghi database. Việc đó là của `topics:import`, sau khi người rà đã xem
 * diff git của JSON.
 */
final class TopicsGenerate extends Command
{
    protected $signature = 'topics:generate
        {--topic= : Chỉ sinh một chủ đề (slug)}
        {--pretend : Chạy thật nhưng KHÔNG ghi file}
        {--force : Ghi đè file đã có}
        {--path= : Thư mục ghi JSON (mặc định database/data/topics)}';

    protected $description = 'Sinh bộ từ chủ đề bằng Gemini (chạy ở máy maintainer)';

    public function handle(TopicGenerator $generator): int
    {
        $topics = $this->targets();

        if ($topics === null) {
            return self::FAILURE;
        }

        $dir = (string) ($this->option('path') ?: database_path('data/topics'));

        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            $this->error("Không tạo được thư mục {$dir}");

            return self::FAILURE;
        }

        $rows = [];
        $failed = 0;

        foreach ($topics as $topic) {
            $path = "{$dir}/{$topic['slug']}.json";

            if (file_exists($path) && ! $this->option('force') && ! $this->option('pretend')) {
                $this->warn("Bỏ qua {$topic['slug']}: file đã có (dùng --force để ghi đè)");

                continue;
            }

            $this->line("Đang sinh <info>{$topic['slug']}</info> (prompt: \"{$topic['prompt_term']}\")…");

            $result = $generator->generate($topic['slug'], $topic['prompt_term']);

            /*
             * CHỈ `Exhausted` mới được ghi. `Throttled` và `Failed` để lại một
             * bộ từ CỤT trông hoàn toàn hợp lệ trong diff git — người rà không
             * phân biệt được nó với "chủ đề nghèo từ", và nó sẽ được commit.
             */
            if (! $result->isWritable()) {
                $failed++;
                $this->error("  {$topic['slug']}: {$result->outcome->value} ({$result->failureReason}) — KHÔNG ghi file");
                $rows[] = $this->row($result, '—');

                continue;
            }

            $written = '—';

            if (! $this->option('pretend')) {
                // Kiểm giá trị trả về: đĩa đầy sẽ cho "ghi xong" và exit 0, rồi
                // `topics:import` nạp một file cụt ở lần chạy sau.
                if (file_put_contents($path, $this->encode($topic['slug'], $result)) === false) {
                    $this->error("Không ghi được {$path}");

                    return self::FAILURE;
                }

                $written = basename($path);
            }

            if ($result->count() < TopicGenerator::MIN_WORDS) {
                $this->warn(sprintf(
                    '  %s: chỉ %d từ (< %d). Chủ đề hẹp — cân nhắc đổi `prompt_term`, KHÔNG ép model bịa thêm.',
                    $topic['slug'],
                    $result->count(),
                    TopicGenerator::MIN_WORDS
                ));
            }

            $rows[] = $this->row($result, $written);
        }

        $this->newLine();
        $this->table(
            ['Chủ đề', 'Kết cục', 'Số từ', 'Vòng', 'Trùng cuối', 'Bị loại', 'File'],
            $rows
        );

        if ($failed > 0) {
            $this->error("{$failed} chủ đề không sinh được. Chạy lại chỉ những chủ đề đó bằng --topic=.");

            return self::FAILURE;
        }

        if ($this->option('pretend')) {
            $this->info('--pretend: không file nào được ghi.');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{slug: string, name: string, prompt_term: string, emoji: string, sort_order: int}>|null
     */
    private function targets(): ?array
    {
        $slug = $this->option('topic');

        if (! is_string($slug) || $slug === '') {
            return TopicCatalog::all();
        }

        $topic = TopicCatalog::find($slug);

        if ($topic === null) {
            $this->error("Slug không có trong TopicCatalog: {$slug}");
            $this->line('Hợp lệ: '.implode(', ', TopicCatalog::slugs()));

            return null;
        }

        return [$topic];
    }

    /** @return array<int, string> */
    private function row(TopicGenerationResult $result, string $written): array
    {
        // Copy cục bộ: `end()` nhận tham chiếu, còn `roundStats` là readonly.
        $stats = $result->roundStats;
        $lastRound = $stats === [] ? null : $stats[count($stats) - 1];

        $rejections = [];

        foreach ($result->rejections as $reason => $count) {
            $rejections[] = "{$reason}={$count}";
        }

        return [
            $result->slug,
            $result->outcome === TopicGenerationOutcome::Exhausted ? 'ok' : $result->outcome->value,
            (string) $result->count(),
            (string) count($result->roundStats),
            $lastRound === null ? '—' : sprintf('%.0f%%', $lastRound['duplicateRatio'] * 100),
            $rejections === [] ? '—' : implode(' ', $rejections),
            $written,
        ];
    }

    private function encode(string $slug, TopicGenerationResult $result): string
    {
        $payload = [
            'slug' => $slug,
            'prompt_version' => TopicPrompt::VERSION,
            'model' => (string) config('services.gemini.model'),
            'generated_at' => now()->toIso8601String(),
            'words' => $result->words,
        ];

        /*
         * `JSON_UNESCAPED_UNICODE` là bắt buộc, không phải thẩm mỹ: không có nó
         * thì mọi chữ Hán thành `东西` và bước RÀ BẰNG MẮT — thứ chống
         * lưng cho cả D3 lẫn D7 — không thực hiện được trên diff git.
         *
         * `JSON_PRETTY_PRINT` để diff theo dòng đọc được khi bộ từ đổi.
         */
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }
}
