<?php

declare(strict_types=1);

namespace App\Services\Topic;

/**
 * Vì sao một từ do model đề xuất KHÔNG vào được bộ từ chủ đề.
 *
 * Sáu lý do, đếm riêng từng loại. Gộp chúng lại thì bảng tổng kết chỉ nói "mất
 * 12 từ" mà không nói mất vì model bịa, vì từ điển thiếu dữ liệu, hay vì chủ đề
 * đã cạn — ba nguyên nhân đòi ba hành động khác nhau.
 */
enum TopicRejection: string
{
    /** Chữ Hán không có trong `dictionary_words`. Model bịa, hoặc trả chữ phồn thể. */
    case NotFound = 'not_found';

    /**
     * Chữ đa âm mà không chấm điểm được cách đọc nào trội hẳn.
     *
     * Phát ở `TopicGenerator`, KHÔNG ở `TopicWordResolver`: resolver truy vấn
     * trên khoá unique `(simplified, pinyin_numbered)` nên theo định nghĩa nó
     * không thể trả về nhiều hơn một dòng.
     */
    case Ambiguous = 'ambiguous';

    /** Không ghép được âm Hán-Việt → ôn tập không dùng được từ này (D13). */
    case MissingHanViet = 'missing_han_viet';

    /** Không có nghĩa tiếng Việt → thẻ học không có nội dung chính. */
    case MissingViGloss = 'missing_vi_gloss';

    /** Đã có ở vòng trước. Tỉ lệ của lý do này là tín hiệu chủ đề đã cạn. */
    case Duplicate = 'duplicate';

    /**
     * Nghĩa đầu lẫn tham chiếu pinyin thô hoặc chữ Hán.
     *
     * Đo trên 8.695 từ đủ điều kiện: 383 từ có dạng `您[nin2]` trong nghĩa đầu,
     * 318 lẫn chữ Hán. Trên màn tra cứu điều đó chấp nhận được — người dùng đang
     * tra. Trên một thẻ DẠY TỪ MỚI thì đó là thứ người học chép vào vở, và nó là
     * lý do quyết định "thẻ không gắn nhãn nguồn AI" chỉ đứng vững khi cổng này
     * tồn tại.
     */
    case DirtyGloss = 'dirty_gloss';
}
