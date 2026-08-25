<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use App\Services\Dictionary\WordSearchService;
use Illuminate\Console\Command;
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
    protected $signature = 'dictionary:benchmark {--runs=25 : Số lần chạy mỗi loại truy vấn}';

    /*
     * KHÔNG còn cờ `--warm`.
     *
     * Nó tồn tại để tắt việc xóa cache của cầu nối tra nghĩa trước mỗi vòng.
     * Cầu nối đã bị xóa: nhánh nghĩa tiếng Việt khớp thẳng lên `definitions_vi`
     * qua GIN index, không còn tầng cache nào của ứng dụng giữa nó với Postgres.
     * Mọi lượt đo bây giờ vốn đã là đường lạnh — giữ lại một núm không còn điều
     * khiển gì là mời người đọc kết luận sai về số đo.
     */
    protected $description = 'Đo p50/p95 cho 11 loại truy vấn + ca đối kháng (R2)';

    /** Ngưỡng của P6. */
    private const TARGET_P95_MS = 150.0;

    /**
     * Dưới ngưỡng này thì nhánh nghĩa tiếng Việt không có gì để khớp, và mọi
     * con số của nó là số đo của một tính năng đang tắt.
     */
    private const MIN_DEFINITIONS_VI = 100_000;

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
        'nghĩa Việt có dấu' => ['con mèo', null],
        'nghĩa Việt không dấu' => ['may tinh', null],
        /*
         * Ca đối kháng THẬT của nhánh nghĩa Việt: fan-out cao nhất đo được trên
         * dữ liệu thật, và là truy vấn người dùng gõ thật chứ không phải chuỗi
         * rác.
         *
         * `người` khớp 5.881 dòng qua vector có dấu, `nguoi` khớp 5.909 dòng qua
         * vector không dấu, và MỖI dòng đó phải chạy `CASE` ba bậc — trong đó
         * bậc 0 mở `definitions_vi` ra bằng `jsonb_array_elements_text`. So với
         * `may tinh` (376 dòng) và `nước` (2.024 dòng), đây mới là ca chậm nhất.
         *
         * Chuỗi rác không thay được: nó không khớp gì nên `CASE` không bao giờ
         * chạy, tức là đo ca RẺ NHẤT rồi gọi nó là đắt nhất.
         */
        'nghĩa Việt fan-out cao (có dấu)' => ['người', null],
        'nghĩa Việt fan-out cao (không dấu)' => ['nguoi', null],
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

    public function handle(WordSearchService $search): int
    {
        $total = DictionaryWord::count();

        if ($total < 100_000) {
            $this->error("Chỉ có {$total} mục — benchmark cần dữ liệu thật, chạy dictionary:import trước.");

            return self::FAILURE;
        }

        /*
         * Gate riêng cho nghĩa tiếng Việt.
         *
         * Không có nó, benchmark chạy trên máy chưa import sẽ thấy vector rỗng,
         * nhánh nghĩa Việt không khớp gì, và lệnh in "ĐẠT" cho một tính năng
         * KHÔNG hề chạy — đúng loại số đo tệ hơn không đo.
         */
        $withVi = DB::table('dictionary_words')->whereNotNull('definitions_vi')->count();

        if ($withVi < self::MIN_DEFINITIONS_VI) {
            $this->error("Chỉ {$withVi} dòng có nghĩa tiếng Việt — chạy cvdict:import trước.");

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
            $timings = $this->measure($search, $query, $mode);

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
    private function measure(WordSearchService $search, string $query, ?string $mode): array
    {
        $runs = max(1, (int) $this->option('runs'));

        // Một lần chạy nháp để cache kế hoạch truy vấn, không tính vào số đo.
        $search->search($query, 1, $mode);

        $timings = [];

        for ($i = 0; $i < $runs; $i++) {
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
