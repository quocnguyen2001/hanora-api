<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use App\Services\Dictionary\VietnameseQueryBridge;
use App\Services\Dictionary\WordSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
    protected $signature = 'dictionary:benchmark
        {--runs=25 : Số lần chạy mỗi loại truy vấn}
        {--warm : Đo đường NÓNG (cache cầu nối đã nạp) thay vì đường lạnh}';

    protected $description = 'Đo p50/p95 cho 9 loại truy vấn + ca đối kháng (R2)';

    /** Ngưỡng của P6. */
    private const TARGET_P95_MS = 150.0;

    /**
     * Mọi ca đo có CÙNG một hình dạng `[truy vấn, mode]`.
     *
     * Bản trước có hai hình dạng — `array<string,string>` cho ca thường và một
     * mảng riêng cho ca mode — rồi nhồi chúng vào một chỗ bằng cách nối chuỗi
     * `$query.'\0'.$mode`. Trong PHP `'\0'` nháy ĐƠN là hai byte `5c 30`, không
     * phải NUL, nên `explode("\0", ...)` không bao giờ tách được: cả bốn ca mode
     * chạy đường auto với một chuỗi truy vấn hỏng, và bảng vẫn in "đạt" cho
     * chúng. Một hình dạng duy nhất làm cả lớp lỗi đó biến mất.
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    private const CASES = [
        'Hán chính xác' => ['学习', null],
        'Hán prefix' => ['学', null],
        'pinyin có dấu' => ['xuéxí', null],
        'pinyin không dấu' => ['xuexi', null],
        'Hán-Việt có dấu' => ['học tập', null],
        'Hán-Việt không dấu' => ['hoc tap', null],
        'gõ sai (trigram)' => ['xuexy', null],
        'nghĩa Việt' => ['con mèo', null],
        /*
         * Ca đối kháng THẬT của nhánh nghĩa Việt.
         *
         * `con` resolve ra ĐỦ trần 6 từ khóa — "child, you, i, young, small,
         * baby" — toàn lexeme tần suất cao, nên GIN trả về ~1.500 dòng và cả
         * ~1.500 dòng đó phải đi qua recheck tsvector. Đo được p50 ~31ms, so với
         * `may tinh` chỉ 2 từ khóa và ~6ms: bản trước lấy `may tinh` làm ca đối
         * kháng, tức là đo ca NHANH NHẤT rồi gọi nó là chậm nhất.
         *
         * Chuỗi `zq` lặp 64 lần không thay được: nó resolve ra rỗng nên nhánh
         * không hề được gắn vào.
         */
        'nghĩa Việt fan-out tối đa' => ['con', null],
    ];

    /**
     * Ca đo theo mode — người dùng tự chọn thay vì để `QueryClassifier` đoán.
     *
     * `xin chào` ở hai mode là ca đắt nhất và cũng là ca lộ rõ nhất vì sao mode
     * tồn tại: cùng một chuỗi, `vi` phải ra 你好 còn `cn` phải ra 新潮.
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    private const MODE_CASES = [
        'mode=vi: xin chào' => ['xin chào', WordSearchService::MODE_VI],
        'mode=vi: con mèo' => ['con mèo', WordSearchService::MODE_VI],
        'mode=cn: xinchao' => ['xinchao', WordSearchService::MODE_CN],
        'mode=cn: xuexi' => ['xuexi', WordSearchService::MODE_CN],
    ];

    public function handle(WordSearchService $search, VietnameseQueryBridge $bridge): int
    {
        $total = DictionaryWord::count();

        if ($total < 100_000) {
            $this->error("Chỉ có {$total} mục — benchmark cần dữ liệu thật, chạy dictionary:import trước.");

            return self::FAILURE;
        }

        /*
         * Gate riêng cho lexicon cầu nối.
         *
         * Không có nó, benchmark chạy trên máy chưa import sẽ thấy `resolve()`
         * trả rỗng, nhánh nghĩa Việt không được gắn, và lệnh in "ĐẠT" cho một
         * tính năng KHÔNG hề chạy.
         */
        $lexicon = DB::table('vi_en_lexicon')->count();

        if ($lexicon < ViLexiconStatus::MIN_ENTRIES) {
            $this->error("Từ điển cầu nối chỉ có {$lexicon} mục — chạy vi-lexicon:import trước.");

            return self::FAILURE;
        }

        $this->info("Benchmark trên {$total} mục, {$this->option('runs')} lần mỗi loại.");
        $this->newLine();

        $cases = self::CASES;
        // Ca đối kháng: `q` dài đúng trần 64 ký tự, toàn rác, không khớp gì —
        // đây là truy vấn đắt nhất mà một request hợp lệ có thể tạo ra.
        $cases['ĐỐI KHÁNG: 64 ký tự rác'] = [str_repeat('zq', 32), null];
        $cases = [...$cases, ...self::MODE_CASES];

        $rows = [];
        $passed = true;

        foreach ($cases as $label => [$query, $mode]) {
            $timings = $this->measure($search, $bridge, $query, $mode);

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
    private function measure(
        WordSearchService $search,
        VietnameseQueryBridge $bridge,
        string $query,
        ?string $mode,
    ): array {
        $runs = max(1, (int) $this->option('runs'));

        // Một lần chạy nháp để cache kế hoạch truy vấn, không tính vào số đo.
        $search->search($query, 1, $mode);

        $timings = [];

        for ($i = 0; $i < $runs; $i++) {
            /*
             * Xóa cache cầu nối TRƯỚC mỗi vòng — đây là mặc định, không phải
             * tùy chọn.
             *
             * Lượt chạy nháp phía trên nạp `Cache::remember` của
             * `VietnameseQueryBridge`, nên nếu không xóa thì cả 25 lượt đo đều
             * là cache hit và đường lạnh — thứ R2 cần chứng minh — không đo được
             * bằng lệnh này. Thực tế cache hit rate còn thấp hơn nữa: màn tìm
             * kiếm bắn request theo từng mốc debounce 250ms, mỗi mốc là một
             * chuỗi chưa từng thấy.
             */
            if (! $this->option('warm')) {
                /*
                 * Xóa ĐÚNG key của truy vấn đang đo, không `Cache::flush()`.
                 *
                 * Flush là `FLUSHDB` trên cả cache store: nó xóa luôn cache
                 * thống kê, phân tích Hán tự, số dòng của health — và chạy
                 * `runs × số loại` lần, tức 250 lần ở mặc định. Lệnh này nằm
                 * ngay cạnh `vi-lexicon:status` trong runbook production.
                 */
                $bridge->forget($query);
            }

            $start = hrtime(true);
            $search->search($query, 1, $mode);
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
