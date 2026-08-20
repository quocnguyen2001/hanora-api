<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

/**
 * Chuẩn hóa pinyin — MỘT nơi duy nhất trong toàn hệ thống (R5 trong plan).
 *
 * Tìm kiếm (P6) và chấm bài mode gõ (P14) phải quy chuỗi về cùng một chuẩn.
 * Nếu hai chỗ đó chuẩn hóa lệch nhau thì người dùng gõ đúng vẫn bị chấm sai,
 * và đó là loại bug gần như không ai tìm ra từ báo cáo của người dùng.
 *
 * Đừng copy logic ở đây đi nơi khác. Gọi service này.
 */
final class PinyinNormalizer
{
    /**
     * Bảng dấu thanh, index 1-4 ứng với thanh 1-4.
     *
     * @var array<string, array<int, string>>
     */
    private const TONE_MARKS = [
        'a' => [1 => 'ā', 2 => 'á', 3 => 'ǎ', 4 => 'à'],
        'e' => [1 => 'ē', 2 => 'é', 3 => 'ě', 4 => 'è'],
        'i' => [1 => 'ī', 2 => 'í', 3 => 'ǐ', 4 => 'ì'],
        'o' => [1 => 'ō', 2 => 'ó', 3 => 'ǒ', 4 => 'ò'],
        'u' => [1 => 'ū', 2 => 'ú', 3 => 'ǔ', 4 => 'ù'],
        'ü' => [1 => 'ǖ', 2 => 'ǘ', 3 => 'ǚ', 4 => 'ǜ'],
        'A' => [1 => 'Ā', 2 => 'Á', 3 => 'Ǎ', 4 => 'À'],
        'E' => [1 => 'Ē', 2 => 'É', 3 => 'Ě', 4 => 'È'],
        'I' => [1 => 'Ī', 2 => 'Í', 3 => 'Ǐ', 4 => 'Ì'],
        'O' => [1 => 'Ō', 2 => 'Ó', 3 => 'Ǒ', 4 => 'Ò'],
        'U' => [1 => 'Ū', 2 => 'Ú', 3 => 'Ǔ', 4 => 'Ù'],
        'Ü' => [1 => 'Ǖ', 2 => 'Ǘ', 3 => 'Ǚ', 4 => 'Ǜ'],
    ];

    /**
     * Ký tự có dấu → ký tự trần. Phủ cả pinyin lẫn tiếng Việt.
     *
     * Tiếng Việt có mặt ở đây vì P6 đẩy thẳng chuỗi người dùng gõ vào `plain()`,
     * và người Việt sẽ gõ tiếng Việt.
     *
     * @var array<string, string>
     */
    private const DIACRITICS = [
        // Pinyin
        'ā' => 'a', 'á' => 'a', 'ǎ' => 'a', 'à' => 'a',
        'ē' => 'e', 'é' => 'e', 'ě' => 'e', 'è' => 'e',
        'ī' => 'i', 'í' => 'i', 'ǐ' => 'i', 'ì' => 'i',
        'ō' => 'o', 'ó' => 'o', 'ǒ' => 'o', 'ò' => 'o',
        'ū' => 'u', 'ú' => 'u', 'ǔ' => 'u', 'ù' => 'u',
        'ǖ' => 'u', 'ǘ' => 'u', 'ǚ' => 'u', 'ǜ' => 'u', 'ü' => 'u',
        // Tiếng Việt — a. `à` và `á` đã có ở khối pinyin bên trên: chúng là
        // CÙNG một codepoint, không phải hai ký tự giống nhau.
        'ạ' => 'a', 'ả' => 'a', 'ã' => 'a',
        'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
        'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
        // Tiếng Việt — e
        'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e',
        'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
        // Tiếng Việt — i
        'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
        // Tiếng Việt — o
        'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o',
        'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
        'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
        // Tiếng Việt — u
        'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u',
        'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
        // Tiếng Việt — y, d
        'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
        'đ' => 'd',
    ];

    /**
     * `xue2 xi2` → `xuéxí`.
     *
     * Nhận dạng số của CC-CEDICT, trả dạng có dấu thanh để hiển thị.
     */
    public function toneMarked(string $numbered): string
    {
        $syllables = preg_split('/\s+/u', trim($numbered), -1, PREG_SPLIT_NO_EMPTY);

        if ($syllables === false || $syllables === []) {
            return '';
        }

        return implode('', array_map($this->markSyllable(...), $syllables));
    }

    /**
     * Quy mọi dạng viết về một chuỗi so khớp được: `xuéxí`, `xue2xi2`,
     * `xue2 xi2`, `XUEXI` đều ra `xuexi`.
     *
     * Chấp nhận cả tiếng Việt có dấu và trả kết quả xác định — không ném lỗi,
     * vì đây là nơi chuỗi người dùng gõ đi qua.
     */
    public function plain(string $input): string
    {
        $stripped = $this->stripDiacritics($input);

        // Chỉ giữ chữ cái latin: bỏ số thanh, khoảng trắng, dấu câu, và cả chữ
        // Hán (chuỗi Hán quy về rỗng, caller tự phân biệt bằng nhánh khác).
        return (string) preg_replace('/[^a-z]/u', '', mb_strtolower($stripped));
    }

    /**
     * Bỏ dấu nhưng GIỮ khoảng trắng và cấu trúc chuỗi.
     *
     * P5 dùng cho `han_viet_plain`: `học tập` → `hoc tap`, hai tiếng vẫn là hai
     * tiếng để prefix match theo tiếng còn hoạt động.
     */
    public function stripDiacritics(string $input): string
    {
        $lowered = mb_strtolower($input);
        $replaced = strtr($lowered, self::DIACRITICS);

        // `u:` là cách CC-CEDICT viết ü.
        return str_replace('u:', 'u', $replaced);
    }

    /**
     * Đặt dấu thanh cho một âm tiết đã tách.
     */
    private function markSyllable(string $syllable): string
    {
        $syllable = str_replace(['u:', 'U:'], ['ü', 'Ü'], $syllable);

        if (preg_match('/^(.*?)([1-5])$/u', $syllable, $matches) !== 1) {
            // Không có số thanh: token không phải pinyin (`t`, `r`) hoặc thanh
            // nhẹ viết không số. Trả nguyên trạng.
            return $this->normalizeUmlautLetter($syllable);
        }

        $base = $this->normalizeUmlautLetter($matches[1]);
        $tone = (int) $matches[2];

        // Thanh 5 là thanh nhẹ — không có dấu.
        if ($tone === 5) {
            return $base;
        }

        $target = $this->toneTargetVowel($base);

        if ($target === null) {
            return $base;
        }

        [$vowel, $offset] = $target;

        return mb_substr($base, 0, $offset)
            .self::TONE_MARKS[$vowel][$tone]
            .mb_substr($base, $offset + 1);
    }

    /**
     * `v` là cách gõ thay cho `ü` trên bàn phím. Quy về `ü`.
     */
    private function normalizeUmlautLetter(string $syllable): string
    {
        return str_replace(['v', 'V'], ['ü', 'Ü'], $syllable);
    }

    /**
     * Chọn nguyên âm mang dấu thanh.
     *
     * Quy tắc chuẩn: có `a` thì đánh vào `a`; không thì `o` hoặc `e`; còn lại
     * đánh vào nguyên âm CUỐI — nhánh cuối này là thứ xử lý đúng `iu` (vào `u`)
     * và `ui` (vào `i`) mà không cần luật riêng cho từng cặp.
     *
     * @return array{0: string, 1: int}|null cặp [nguyên âm, vị trí]
     */
    private function toneTargetVowel(string $syllable): ?array
    {
        $letters = mb_str_split($syllable);
        $vowelPositions = [];

        foreach ($letters as $index => $letter) {
            if (isset(self::TONE_MARKS[$letter])) {
                $vowelPositions[$index] = $letter;
            }
        }

        if ($vowelPositions === []) {
            return null;
        }

        foreach (['a', 'A'] as $priority) {
            $found = array_search($priority, $vowelPositions, true);
            if ($found !== false) {
                return [$priority, (int) $found];
            }
        }

        foreach (['o', 'O', 'e', 'E'] as $priority) {
            $found = array_search($priority, $vowelPositions, true);
            if ($found !== false) {
                return [$priority, (int) $found];
            }
        }

        $lastPosition = array_key_last($vowelPositions);

        return [$vowelPositions[$lastPosition], (int) $lastPosition];
    }
}
