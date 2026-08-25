<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use Normalizer;

/**
 * Chuẩn hóa truy vấn tiếng Việt trước khi khớp lên `definitions_vi`.
 *
 * Cầu nối hai chặng qua gloss tiếng Anh đã bị xóa, nhưng việc bỏ loại từ thì
 * không mất đi cùng nó — đó là phần duy nhất của nó vẫn đúng. Loại từ là hư từ
 * ngữ pháp; từ điển KHÔNG bao giờ liệt kê chúng trong nghĩa. `plainto_tsquery`
 * nối các tiếng bằng AND, nên `con mèo` đòi cả `con` lẫn `mèo` phải có mặt —
 * mà nghĩa của 猫 chỉ có `mèo`. Kết quả đo được: 猫儿山 ("Núi Mèo Con") thắng
 * 猫.
 */
final class VietnameseQueryNormalizer
{
    /**
     * Loại từ và danh từ đơn vị đứng đầu ngữ.
     *
     * `xin` KHÔNG còn trong danh sách này, khác bản cầu nối. Với nghĩa tiếng
     * Việt trực tiếp, `xin chào` khớp NGUYÊN VĂN nghĩa đầu của 你好 — bỏ `xin`
     * là tự tay hạ một khớp trọn vẹn xuống thành khớp một phần.
     */
    private const LEADING_CLASSIFIERS = [
        'con', 'cái', 'chiếc', 'cây', 'quả', 'trái', 'bức', 'tấm',
        'cuốn', 'quyển', 'ngôi', 'căn', 'người', 'sự', 'việc', 'cuộc',
    ];

    /**
     * Dạng bỏ dấu của danh sách trên, dựng một lần cho mỗi instance.
     *
     * Hằng số ở trên giữ dấu vì đó là cách đọc được; so khớp thì phải bỏ dấu vì
     * `con meo` cũng phải bỏ được `con`.
     *
     * @var list<string>
     */
    private readonly array $classifiersPlain;

    public function __construct(private readonly PinyinNormalizer $pinyin)
    {
        $this->classifiersPlain = array_map(
            fn (string $word): string => $this->pinyin->stripDiacritics($word),
            self::LEADING_CLASSIFIERS
        );
    }

    /**
     * Thường hóa, bỏ dấu câu, gộp khoảng trắng. Giữ nguyên dấu thanh.
     */
    public function normalize(string $query): string
    {
        $query = Normalizer::normalize(trim($query), Normalizer::FORM_C) ?: $query;
        $query = mb_strtolower($query);
        $query = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query);

        return trim((string) preg_replace('/\s+/u', ' ', $query));
    }

    /**
     * Bỏ loại từ đứng đầu, nếu có và nếu còn lại thứ gì đó.
     *
     * So khớp trên dạng BỎ DẤU: người Việt gõ không dấu là chuyện thường, và
     * `con meo` phải bỏ được `con` y như `con mèo`.
     *
     * Chỉ bỏ khi truy vấn có nhiều hơn một tiếng. Ai gõ trần `con` là đang tra
     * chính từ đó, và `người` một mình là một mục từ điển thật.
     *
     * Bỏ ĐÚNG MỘT loại từ, không lặp: `cái con` không phải ngữ tiếng Việt, và
     * bóc nhiều lớp là đường dẫn tới việc bóc sạch một truy vấn hợp lệ.
     */
    public function withoutLeadingClassifier(string $normalized): string
    {
        $tokens = explode(' ', $normalized);

        if (count($tokens) < 2) {
            return $normalized;
        }

        if (! in_array($this->pinyin->stripDiacritics($tokens[0]), $this->classifiersPlain, true)) {
            return $normalized;
        }

        return implode(' ', array_slice($tokens, 1));
    }

    /**
     * Truy vấn có mang dấu thanh hay không.
     *
     * Quyết định vector nào được dùng, và đó là quyết định quan trọng nhất của
     * cả nhánh. Truy vấn CÓ DẤU đi vector có dấu; truy vấn không dấu đi vector
     * không dấu. Trộn hai đường lại là mời những khớp trùng hợp ngẫu nhiên lên
     * đầu: đo được `may tinh` khớp `may` và `tinh` CÓ DẤU trong nghĩa của 吉凶
     * ("may mắn"), và 吉凶 đứng trước 电脑.
     */
    public function isAccented(string $normalized): bool
    {
        return $normalized !== $this->pinyin->stripDiacritics($normalized);
    }
}
