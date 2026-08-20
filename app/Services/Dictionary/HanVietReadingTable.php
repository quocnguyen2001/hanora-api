<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use RuntimeException;

/**
 * Bảng tra âm Hán-Việt cho từng ký tự, gộp từ hai nguồn.
 *
 * ## Vì sao cần hai nguồn
 *
 * Đo trên tập ưu tiên thật (8.848 mục):
 *
 * | Nguồn | `ok` |
 * |---|---|
 * | Chỉ Unihan `kVietnamese` | **71,2%** — vừa đủ qua gate 70% của P5 |
 * | Unihan + bảng bổ sung | **99,6%** |
 *
 * Unihan hỏng ở hai chỗ:
 *
 * 1. **Thiếu ký tự thường dùng.** `面`, `說`, `愛`, `電`, `以`, `為` đều không có
 *    `kVietnamese`. Đây không phải ký tự hiếm.
 * 2. **Khóa theo ký tự, không theo cách đọc.** Unihan chỉ cho 行 một âm là
 *    `hàng`, nên `行走` sẽ ra `hàng tẩu` thay vì `hành tẩu` — sai im lặng, đúng
 *    loại lỗi mà R6 trong plan cảnh báo.
 *
 * Bảng bổ sung khóa theo **(ký tự, âm tiết pinyin)** nên phân giải được chữ đa
 * âm bằng chính pinyin của mục từ:
 *
 *     行 hang2 -> hàng      銀行 -> ngân hàng
 *     行 xing2 -> hành      行走 -> hành tẩu
 *
 * ## Thứ tự ưu tiên
 *
 * 1. (ký tự, âm tiết pinyin) trong bảng bổ sung — chính xác nhất.
 * 2. Ký tự trong bảng bổ sung, nếu chỉ có đúng một âm.
 * 3. Ký tự trong Unihan, nếu chỉ có đúng một âm.
 * 4. Còn lại: nhập nhằng (biết âm nhưng không chọn được) hoặc thiếu hẳn.
 *
 * Tra theo chữ PHỒN THỂ, không phải giản thể: âm Hán-Việt gắn với tự dạng
 * truyền thống, và cả hai nguồn đều khóa theo đó.
 */
final class HanVietReadingTable
{
    /** Ký tự có âm nhưng không chọn được âm nào. */
    public const AMBIGUOUS = null;

    /** @var array<string, string> "ký tự\0âm tiết" => âm Hán-Việt */
    private array $byCharacterAndPinyin = [];

    /** @var array<string, list<string>> ký tự => danh sách âm, từ bảng bổ sung */
    private array $supplementByCharacter = [];

    /** @var array<string, list<string>> ký tự => danh sách âm, từ Unihan */
    private array $unihanByCharacter = [];

    /**
     * Hai nguồn giữ RIÊNG, không gộp chung một mảng.
     *
     * Gộp lại là một bug thật đã gặp: 東 được bảng bổ sung cho đúng một âm
     * `đông`, còn Unihan cho `đang đông`. Hợp hai tập lại thành hai âm khiến
     * `東西` bị đánh `ambiguous` và biến mất khỏi giao diện, dù nguồn tốt hơn đã
     * trả lời dứt khoát. Âm `đang` là âm cổ hiếm, không nên tạo ra nhập nhằng.
     *
     * @param  array<string, list<string>>  $unihanReadings
     */
    public function __construct(string $supplementCsvPath, array $unihanReadings)
    {
        $this->unihanByCharacter = $unihanReadings;
        $this->loadSupplement($supplementCsvPath);
    }

    /**
     * Tra âm Hán-Việt của một ký tự.
     *
     * @param  string|null  $pinyinSyllable  âm tiết pinyin dạng số (`hang2`)
     * @return string|false|null âm; `null` nhập nhằng; `false` thiếu hẳn
     */
    public function lookup(string $character, ?string $pinyinSyllable = null): string|false|null
    {
        if ($pinyinSyllable !== null) {
            $key = $character."\0".mb_strtolower($pinyinSyllable);

            if (isset($this->byCharacterAndPinyin[$key])) {
                return $this->byCharacterAndPinyin[$key];
            }
        }

        // Bảng bổ sung là nguồn chính; chỉ khi nó im lặng mới hỏi tới Unihan.
        $candidates = $this->supplementByCharacter[$character]
            ?? $this->unihanByCharacter[$character]
            ?? null;

        if ($candidates === null) {
            return false;
        }

        // Nhiều âm mà pinyin không giúp phân giải: thà để trống còn hơn đoán.
        // Người học sẽ nhớ cái sai, và đó là thiệt hại thẳng vào giá trị sản phẩm.
        return count($candidates) === 1 ? $candidates[0] : self::AMBIGUOUS;
    }

    public function characterCount(): int
    {
        return count($this->supplementByCharacter + $this->unihanByCharacter);
    }

    private function loadSupplement(string $path): void
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Không đọc được bảng Hán-Việt bổ sung: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Không mở được bảng Hán-Việt bổ sung: {$path}");
        }

        try {
            $header = fgetcsv($handle, escape: '');

            if ($header === false) {
                return;
            }

            while (($row = fgetcsv($handle, escape: '')) !== false) {
                [$character, $rawReadings, $pinyin] = array_pad($row, 3, '');

                $readings = $this->parseReadingList((string) $rawReadings);

                if ($character === '' || $readings === []) {
                    continue;
                }

                // `*` nghĩa là nguồn không gắn âm tiết pinyin cụ thể.
                if ($pinyin !== '' && $pinyin !== '*') {
                    $this->byCharacterAndPinyin[$character."\0".mb_strtolower((string) $pinyin)] = $readings[0];
                }

                $existing = $this->supplementByCharacter[$character] ?? [];
                $this->supplementByCharacter[$character] = array_values(array_unique([...$existing, ...$readings]));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Cột `hanviet` của nguồn là literal list kiểu Python: `['hành', 'hạnh']`.
     *
     * @return list<string>
     */
    private function parseReadingList(string $raw): array
    {
        if (preg_match_all("/'([^']+)'/u", $raw, $matches) < 1) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), $matches[1])));
    }
}
