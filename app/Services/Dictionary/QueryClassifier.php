<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

/**
 * Phân loại chuỗi tìm kiếm thành ba lớp: Hán, Việt, pinyin.
 *
 * BA lớp chứ không phải hai. Chia đôi Hán/latin là sai: âm Hán-Việt cũng là
 * latin, và nếu đẩy nó qua `PinyinNormalizer` rồi so trigram với `pinyin_plain`
 * thì chỉ sinh ra ứng viên vô nghĩa.
 *
 * Chạy cả 7 nhánh cho mọi truy vấn cũng sai theo hướng ngược lại — lãng phí
 * trên bảng 120k dòng.
 */
final class QueryClassifier
{
    public const CLASS_HAN = 'han';

    public const CLASS_VIETNAMESE = 'vietnamese';

    public const CLASS_PINYIN = 'pinyin';

    /**
     * Dấu CHỈ có trong tiếng Việt, không thuộc bộ dấu thanh pinyin.
     *
     * `à á è é ì í ò ó ù ú` cố tình KHÔNG có ở đây: chúng dùng chung codepoint
     * với dấu thanh pinyin, nên gặp chúng không kết luận được gì.
     */
    private const VIETNAMESE_ONLY = 'ăâđêôơưạảãấầẩẫậắằẳẵặẹẻẽếềểễệỉịọỏốồổỗộớờởỡợụủứừửữựỳỵỷỹ';

    /**
     * Chuỗi không dấu như `hoc tap` mơ hồ giữa pinyin và Hán-Việt. Trả `pinyin`
     * cho lớp, nhưng `mayBeVietnamese()` bật để service chạy CẢ nhánh Hán-Việt
     * rồi để bảng xếp hạng quyết định.
     *
     * Đây là ca thường gặp nhất, vì người Việt hay gõ không dấu.
     */
    public function classify(string $query): string
    {
        $query = trim($query);

        if ($this->containsHan($query)) {
            return self::CLASS_HAN;
        }

        if ($this->containsVietnameseOnlyDiacritics($query)) {
            return self::CLASS_VIETNAMESE;
        }

        return self::CLASS_PINYIN;
    }

    /**
     * Truy vấn latin không dấu có thể là Hán-Việt viết tắt dấu.
     */
    public function mayBeVietnamese(string $query): bool
    {
        $class = $this->classify($query);

        if ($class === self::CLASS_VIETNAMESE) {
            return true;
        }

        // Có khoảng trắng là dấu hiệu mạnh: pinyin nhiều âm tiết người ta
        // thường gõ dính (`xuexi`), còn Hán-Việt thì tách tiếng (`hoc tap`).
        return $class === self::CLASS_PINYIN && $query !== '';
    }

    public function containsHan(string $query): bool
    {
        return preg_match('/\p{Han}/u', $query) === 1;
    }

    private function containsVietnameseOnlyDiacritics(string $query): bool
    {
        $lowered = mb_strtolower($query);

        foreach (mb_str_split(self::VIETNAMESE_ONLY) as $character) {
            if (str_contains($lowered, $character)) {
                return true;
            }
        }

        return false;
    }
}
