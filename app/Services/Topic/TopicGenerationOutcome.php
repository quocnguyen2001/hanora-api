<?php

declare(strict_types=1);

namespace App\Services\Topic;

/**
 * Kết quả sinh bộ từ cho MỘT chủ đề.
 *
 * Ba kết cục phải phân biệt được, và đó là bài học đắt nhất của red team: nếu
 * 429 rơi vào cùng nhánh với "chủ đề đã cạn" thì một vòng bị chặn trả 0 từ,
 * generator kết luận "model đã vét cạn", ghi ra một file JSON cụt trông hoàn
 * toàn hợp lệ, và người rà commit nó vào git mà không có cách nào nhận ra.
 */
enum TopicGenerationOutcome: string
{
    /** Đã cạn vốn từ thông dụng — kết cục THÀNH CÔNG. Chỉ ca này được ghi file. */
    case Exhausted = 'exhausted';

    /** Chạm trần Gemini và không hồi phục trong số lần thử cho phép. */
    case Throttled = 'throttled';

    /** Lỗi khác: mạng, JSON rác, thiếu key. */
    case Failed = 'failed';
}
