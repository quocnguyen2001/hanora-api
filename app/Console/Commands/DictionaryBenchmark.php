<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use App\Services\Dictionary\WordSearchService;
use Illuminate\Console\Command;

/**
 * Benchmark bắt buộc của P6 (R2 trong plan).
 *
 * Đo ở tầng service chứ không qua HTTP: throttle 60/phút của P1 sẽ chặn ngay ở
 * vòng lặp thứ 60, và thứ R2 cần chứng minh là PostgreSQL đủ nhanh trên ~120k
 * dòng, không phải rate limiter hoạt động.
 *
 * Chỉ cân nhắc Meilisearch khi lệnh này cho ra số không đạt — và trình bày
 * bằng chính số đó.
 */
final class DictionaryBenchmark extends Command
{
    protected $signature = 'dictionary:benchmark {--runs=25 : Số lần chạy mỗi loại truy vấn}';

    protected $description = 'Đo p50/p95 cho 7 loại truy vấn + ca đối kháng (R2)';

    /** Ngưỡng của P6. */
    private const TARGET_P95_MS = 150.0;

    /** @var array<string, string> */
    private const CASES = [
        'Hán chính xác' => '学习',
        'Hán prefix' => '学',
        'pinyin có dấu' => 'xuéxí',
        'pinyin không dấu' => 'xuexi',
        'Hán-Việt có dấu' => 'học tập',
        'Hán-Việt không dấu' => 'hoc tap',
        'gõ sai (trigram)' => 'xuexy',
    ];

    public function handle(WordSearchService $search): int
    {
        $total = DictionaryWord::count();

        if ($total < 100_000) {
            $this->error("Chỉ có {$total} mục — benchmark cần dữ liệu thật, chạy dictionary:import trước.");

            return self::FAILURE;
        }

        $this->info("Benchmark trên {$total} mục, {$this->option('runs')} lần mỗi loại.");
        $this->newLine();

        $cases = self::CASES;
        // Ca đối kháng: `q` dài đúng trần 64 ký tự, toàn rác, không khớp gì —
        // đây là truy vấn đắt nhất mà một request hợp lệ có thể tạo ra.
        $cases['ĐỐI KHÁNG: 64 ký tự rác'] = str_repeat('zq', 32);

        $rows = [];
        $passed = true;

        foreach ($cases as $label => $query) {
            $timings = $this->measure($search, $query);

            $p50 = $this->percentile($timings, 0.50);
            $p95 = $this->percentile($timings, 0.95);
            $withinTarget = $p95 < self::TARGET_P95_MS;
            $passed = $passed && $withinTarget;

            $rows[] = [
                $label,
                sprintf('%.1f ms', $p50),
                sprintf('%.1f ms', $p95),
                sprintf('%.1f ms', max($timings)),
                $withinTarget ? 'đạt' : 'VƯỢT NGƯỠNG',
            ];
        }

        $this->table(['Loại truy vấn', 'p50', 'p95', 'max', 'Kết luận'], $rows);

        if (! $passed) {
            $this->error(sprintf('KHÔNG ĐẠT: có loại truy vấn p95 >= %.0fms.', self::TARGET_P95_MS));
            $this->line('Thử theo thứ tự: (1) thêm/sửa index, (2) tách truy vấn nghĩa khỏi truy vấn chữ,');
            $this->line('(3) materialized view cho tập ưu tiên. Meilisearch chỉ vào bàn sau khi cả ba đã đo.');

            return self::FAILURE;
        }

        $this->info(sprintf('ĐẠT: p95 < %.0fms cho mọi loại truy vấn.', self::TARGET_P95_MS));

        return self::SUCCESS;
    }

    /**
     * @return list<float>
     */
    private function measure(WordSearchService $search, string $query): array
    {
        $runs = max(1, (int) $this->option('runs'));

        // Một lần chạy nháp để cache kế hoạch truy vấn, không tính vào số đo.
        $search->search($query, 1);

        $timings = [];

        for ($i = 0; $i < $runs; $i++) {
            $start = hrtime(true);
            $search->search($query, 1);
            $timings[] = (hrtime(true) - $start) / 1_000_000;
        }

        return $timings;
    }

    /**
     * @param  list<float>  $timings
     */
    private function percentile(array $timings, float $percentile): float
    {
        sort($timings);
        $index = (int) ceil($percentile * count($timings)) - 1;

        return $timings[max(0, $index)];
    }
}
