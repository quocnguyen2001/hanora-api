<?php

declare(strict_types=1);

namespace App\Services\Topic;

/**
 * Chuẩn hoá pinyin dạng CÓ SỐ (`xue2 xi2`) để so khớp khoá tự nhiên.
 *
 * **Vì sao không dùng `PinyinNormalizer`.** Class đó có ba method —
 * `toneMarked()`, `plain()`, `stripDiacritics()` — và không method nào phục vụ
 * việc này. `plain()` chạy `preg_replace('/[^a-z]/u', '', ...)` nên nó XOÁ SẠCH
 * số thanh, đúng thứ mà khoá tự nhiên `(simplified, pinyin_numbered)` tồn tại để
 * giữ.
 *
 * Đo trên tập 8.695 từ đủ điều kiện: bỏ số thanh gộp trùng **491 hình chữ /
 * 1.022 dòng** (~12%). `好 hao3` và `好 hao4` cùng quy về `hao`; `东西 dong1 xi1`
 * ("đông và tây") và `dong1 xi5` ("đồ vật") cùng quy về `dongxi`. Dùng nhầm
 * `plain()` ở đây sẽ biến mọi chữ đa âm thành một ca `ambiguous`, kéo độ phủ
 * xuống dưới cổng 95% và làm `topics:import` fail giữa lúc deploy mà không ai
 * hiểu vì sao.
 *
 * Nên ở đây CHỈ chuẩn hoá khoảng trắng và chữ hoa/thường. Số thanh giữ nguyên.
 */
final class NumberedPinyin
{
    /**
     * `  Xue2   xi2 ` → `xue2 xi2`.
     *
     * Chữ thường vì CC-CEDICT viết hoa âm tiết của danh từ riêng (`Hua1` cho họ
     * `花`), và khoá tự nhiên trong `dictionary_words` giữ nguyên dạng đó. So
     * khớp phải bỏ qua khác biệt hoa/thường, nếu không `Hua1` trong JSON sẽ
     * không khớp `Hua1` trong DB chỉ vì người rà gõ lại bằng chữ thường.
     */
    public static function normalize(string $pinyin): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($pinyin));

        return mb_strtolower($collapsed ?? '');
    }

    /**
     * Hai chuỗi pinyin có số chỉ về cùng một cách đọc, BỎ QUA hoa/thường?
     *
     * Dùng để thu hẹp danh sách ứng viên, KHÔNG dùng để chốt một ứng viên.
     * CC-CEDICT viết hoa âm tiết của danh từ riêng, nên `Mei3` (Châu Mỹ) và
     * `mei3` (đẹp) cùng khớp ở đây — đó là hai mục từ khác hẳn nhau.
     */
    public static function matches(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    /**
     * Khớp CHÍNH XÁC, giữ nguyên hoa/thường.
     *
     * Đây mới là phép so dùng để chốt một cách đọc. Chỉ chuẩn hoá khoảng trắng.
     *
     * Vì sao cần cả hai: `matches()` bỏ qua hoa/thường để người rà gõ lại
     * `Hua1` thành `hua1` vẫn khớp; nhưng khi hai mục CHỈ khác nhau ở hoa/thường
     * thì đúng chỗ đó lại là thứ phân biệt chúng. Đo trên lần sinh thật: 5 mục
     * chọn nhầm cách đọc danh từ riêng vì chỉ có `matches()`.
     */
    public static function matchesExact(string $a, string $b): bool
    {
        $collapse = static fn (string $s): string => (string) preg_replace('/\s+/u', ' ', trim($s));

        return $collapse($a) === $collapse($b);
    }
}
