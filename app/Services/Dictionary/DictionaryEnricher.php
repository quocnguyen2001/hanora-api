<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gắn `hsk_level`, `frequency_rank`, `is_priority` cho dữ liệu đã import.
 *
 * Chạy SAU khi `dictionary_words` đã có dữ liệu, vì cả ba thứ đều là phép nối
 * theo chữ giản thể chứ không phải thuộc tính đọc ra được từ CC-CEDICT.
 */
final class DictionaryEnricher
{
    /**
     * Ngưỡng tập ưu tiên, ĐÃ ĐO chứ không đoán (D5).
     *
     * Đo trên nguồn thật, |HSK 2.0 ∪ SUBTLEX top-N| sau khi giao với CC-CEDICT:
     *
     *   N = 2000 ->  5.747      N = 5000 -> 7.427
     *   N = 3000 ->  6.269      N = 6000 -> 8.094   <- chọn
     *   N = 4000 ->  6.835      N = 8000 -> 9.528
     *
     * Đúng như red team H10 cảnh báo, N = 5000 KHÔNG chạm được mục tiêu
     * 8.000–10.000 vì HSK 2.0 trùng nặng với top-5000 SUBTLEX. Chọn N = 6000 để
     * rơi vào đầu dải: tập càng gọn thì khối lượng rà tay Hán-Việt ở P5 càng nhỏ.
     */
    public const FREQUENCY_THRESHOLD = 6000;

    /** HSK 2.0 = 6 cấp. Nguồn dùng tiền tố `old-` cho bộ này (V2). */
    private const HSK_LEVEL_PREFIX = 'old-';

    /**
     * @return array{matched: int, ambiguous: int}
     */
    public function applyHskLevels(string $hskJsonPath): array
    {
        $levels = $this->readHskLevels($hskJsonPath);

        DB::statement('CREATE TEMPORARY TABLE IF NOT EXISTS tmp_hsk (word text PRIMARY KEY, level smallint NOT NULL)');
        DB::statement('TRUNCATE tmp_hsk');

        foreach (array_chunk($levels, 2000, true) as $chunk) {
            $rows = [];

            foreach ($chunk as $word => $level) {
                $rows[] = ['word' => $word, 'level' => $level];
            }

            DB::table('tmp_hsk')->insertOrIgnore($rows);
        }

        /*
         * Danh sách HSK chỉ có chuỗi giản thể, còn khóa tự nhiên của ta là
         * (giản thể, pinyin số) — một chữ đa âm có nhiều dòng.
         *
         * Luật cho từ đa âm: gắn cho dòng có `frequency_rank` NHỎ NHẤT trong
         * nhóm, tức cách đọc phổ biến nhất. `DISTINCT ON` cộng `ORDER BY` dưới
         * đây chính là luật đó, diễn đạt bằng một câu lệnh.
         */
        $matched = DB::update(<<<'SQL'
            UPDATE dictionary_words AS dw
            SET hsk_level = chosen.level
            FROM (
                SELECT DISTINCT ON (d.simplified) d.id, h.level
                FROM tmp_hsk AS h
                JOIN dictionary_words AS d ON d.simplified = h.word
                ORDER BY d.simplified, d.frequency_rank ASC NULLS LAST, d.pinyin_numbered ASC
            ) AS chosen
            WHERE dw.id = chosen.id
        SQL);

        /*
         * Từ đa âm mà KHÔNG dòng nào có tần suất: luật ở trên rơi về "dòng đầu
         * theo pinyin", tức là một phỏng đoán. Đếm lại để P5 rà tay, không giấu.
         */
        $ambiguous = (int) DB::selectOne(<<<'SQL'
            SELECT count(*) AS total FROM (
                SELECT d.simplified
                FROM tmp_hsk AS h
                JOIN dictionary_words AS d ON d.simplified = h.word
                GROUP BY d.simplified
                HAVING count(*) > 1 AND count(d.frequency_rank) = 0
            ) AS guessed
        SQL)->total;

        DB::statement('DROP TABLE IF EXISTS tmp_hsk');

        return ['matched' => $matched, 'ambiguous' => $ambiguous];
    }

    /**
     * SUBTLEX-CH đã sắp sẵn theo tần suất giảm dần, nên thứ tự trong file chính
     * là hạng. Hạng gắn cho MỌI dòng cùng chữ giản thể: tần suất là thuộc tính
     * của mặt chữ, không phân biệt cách đọc.
     */
    public function applyFrequencyRanks(string $subtlexJsonPath): int
    {
        $words = $this->readFrequencyOrder($subtlexJsonPath);

        /*
         * Đổ hạng vào bảng tạm rồi UPDATE ... FROM một lần.
         *
         * Cách hiển nhiên hơn — một CASE WHEN khổng lồ theo lô — vừa chậm vừa
         * dựng câu SQL dài hàng chục nghìn ký tự cho mỗi lô. Bảng tạm cho
         * Postgres một phép nối hash bình thường trên 99k dòng.
         */
        DB::statement('CREATE TEMPORARY TABLE IF NOT EXISTS tmp_frequency (word text PRIMARY KEY, rank integer NOT NULL)');
        DB::statement('TRUNCATE tmp_frequency');

        foreach (array_chunk($words, 2000, true) as $chunk) {
            $rows = [];

            foreach ($chunk as $rank => $word) {
                $rows[] = ['word' => $word, 'rank' => $rank];
            }

            // Nguồn có thể lặp từ; giữ hạng tốt nhất (nhỏ nhất).
            DB::table('tmp_frequency')->insertOrIgnore($rows);
        }

        $updated = DB::update(<<<'SQL'
            UPDATE dictionary_words AS dw
            SET frequency_rank = tf.rank
            FROM tmp_frequency AS tf
            WHERE dw.simplified = tf.word
        SQL);

        DB::statement('DROP TABLE IF EXISTS tmp_frequency');

        return $updated;
    }

    /**
     * `is_priority` = HSK 2.0 ∪ (frequency_rank <= ngưỡng).
     */
    public function applyPrioritySet(): int
    {
        DB::table('dictionary_words')->update(['is_priority' => false]);

        return DB::table('dictionary_words')
            ->whereNotNull('hsk_level')
            ->orWhere('frequency_rank', '<=', self::FREQUENCY_THRESHOLD)
            ->update(['is_priority' => true]);
    }

    /**
     * @return array<string, int> giản thể => cấp HSK 1-6
     */
    private function readHskLevels(string $path): array
    {
        $entries = $this->readJson($path);
        $levels = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! isset($entry['simplified'])) {
                continue;
            }

            $entryLevels = is_array($entry['level'] ?? null) ? $entry['level'] : [];

            foreach ($entryLevels as $raw) {
                if (! is_string($raw) || ! str_starts_with($raw, self::HSK_LEVEL_PREFIX)) {
                    continue;
                }

                $level = (int) substr($raw, strlen(self::HSK_LEVEL_PREFIX));

                if ($level < 1 || $level > 6) {
                    continue;
                }

                $word = (string) $entry['simplified'];

                // Một từ có thể xuất hiện ở nhiều cấp; giữ cấp THẤP nhất, vì đó
                // là lúc người học gặp nó lần đầu.
                $levels[$word] = isset($levels[$word]) ? min($levels[$word], $level) : $level;
            }
        }

        return $levels;
    }

    /**
     * @return array<int, string> hạng (bắt đầu từ 1) => giản thể
     */
    private function readFrequencyOrder(string $path): array
    {
        $payload = $this->readJson($path);
        $rows = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $order = [];
        $rank = 0;

        foreach ($rows as $row) {
            $word = is_array($row) ? ($row['Word'] ?? null) : null;

            if (! is_string($word) || $word === '') {
                continue;
            }

            $order[++$rank] = $word;
        }

        return $order;
    }

    /**
     * @return array<mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Không đọc được file: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("File không phải JSON hợp lệ: {$path}");
        }

        return $decoded;
    }
}
