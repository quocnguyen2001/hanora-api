<?php

declare(strict_types=1);

namespace App\Services\Topic;

/**
 * Rút "nghĩa để DẠY" từ nghĩa CVDICT thô.
 *
 * Một nguồn duy nhất cho cả hai chỗ dùng: `TopicWordResolver` gọi nó để GÁC,
 * `TopicWordResource` gọi nó để HIỂN THỊ. Tách hai đường sẽ cho ra một bộ từ
 * qua được cổng nhờ nghĩa sạch rồi hiện nghĩa bẩn trên thẻ.
 *
 * KHÔNG lưu thành cột: đây là hàm thuần trên dữ liệu đã có, và một cột phái
 * sinh nữa là một cột nữa có thể lệch với nguồn.
 */
final class TopicGloss
{
    /**
     * Chú thích ĐUÔI trong ngoặc — cùng biểu thức mà cột generated
     * `definitions_vi_first_glosses` đang dùng, giữ nguyên để hai bên không lệch.
     *
     * Đây là chỗ chứa phần lớn rác: `梦` → "giấc mơ (LT: 場|场[chang2],個|个[ge4])",
     * `你` → "bạn (ngôi thứ hai thông dụng, khác với kính trọng 您[nin2])". Nghĩa
     * thật nằm ngoài ngoặc và hoàn toàn dùng được.
     */
    private const TRAILING_NOTE = '/(\s*\([^)]*\))+\s*(?=;|$)/u';

    /**
     * Mục SIÊU DỮ LIỆU, không phải nghĩa.
     *
     * Đo trên 560 mục bẩn của tập ưu tiên: 238 "họ X", 134 "biến thể của X",
     * 51 "dùng trong / viết tắt của X". Chúng không có nghĩa nào để dạy —
     * `碰` → "biến thể của 碰[peng4]" nói đúng không gì cả. Khác hẳn nhóm 137
     * mục còn lại, vốn có nghĩa thật kèm chú thích.
     */
    private const METADATA_ENTRY = '/^(họ\s|biến thể|dùng trong|viết tắt)/iu';

    /** Còn sót tham chiếu pinyin thô (`nin2`) hoặc chữ Hán sau khi đã dọn. */
    private const STILL_DIRTY = '/[a-z]+[0-9]|\p{Han}/u';

    /**
     * Nghĩa đầu tiên DÙNG ĐƯỢC trong cả mảng, không phải nghĩa ở index 0.
     *
     * Đây là điểm khác biệt quan trọng nhất của lớp này, và nó bám thẳng vào
     * điều README đã cảnh báo: *"THỨ TỰ nghĩa mới là phần hại nhất"*. CVDICT
     * thường xếp một mục siêu dữ liệu lên đầu cho những chữ giản thể vốn là
     * biến thể của chữ phồn thể, và nghĩa thật nằm ngay sau đó:
     *
     *   家 → ["dùng trong 傢伙…", "nhà", "gia đình", …]
     *   笑 → ["biến thể cũ của 笑[xiao4]", "cười; mỉm cười", …]
     *   岁 → ["biến thể của 歲|岁[sui4], năm", "tuổi", …]
     *
     * Chỉ đọc index 0 sẽ đánh rơi `家` khỏi chủ đề "gia đình" và `笑` khỏi chủ
     * đề cảm xúc — đúng những từ mà chủ đề đó tồn tại để dạy.
     *
     * @param  list<string>|null  $glosses
     */
    public static function teachingFrom(?array $glosses): ?string
    {
        foreach ($glosses ?? [] as $gloss) {
            $teaching = self::teaching($gloss);

            if ($teaching !== null) {
                return $teaching;
            }
        }

        return null;
    }

    /**
     * Nghĩa dùng được để dạy từ MỘT chuỗi, hoặc `null` nếu không dạy được.
     *
     * `null` là kết cục HỢP LỆ và thường gặp — mục siêu dữ liệu chiếm 423/560
     * mục bẩn — nên caller xử lý nó như một nhánh bình thường, không như lỗi.
     */
    public static function teaching(?string $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        // Loại mục siêu dữ liệu TRƯỚC khi dọn: "biến thể của 碰[peng4]" không có
        // ngoặc đuôi để cắt, và cắt xong vẫn là một mục không dạy được.
        if (preg_match(self::METADATA_ENTRY, trim($raw)) === 1) {
            return null;
        }

        $cleaned = trim((string) preg_replace(self::TRAILING_NOTE, '', $raw));
        $cleaned = trim((string) preg_replace('/\s*;\s*/u', '; ', $cleaned), " \t\n\r\0\x0B;");

        if ($cleaned === '' || preg_match(self::STILL_DIRTY, mb_strtolower($cleaned)) === 1) {
            return null;
        }

        return $cleaned;
    }
}
