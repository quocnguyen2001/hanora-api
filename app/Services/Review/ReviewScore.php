<?php

declare(strict_types=1);

namespace App\Services\Review;

/**
 * Công thức chấm điểm — MỘT nguồn duy nhất cho cả app.
 *
 * Class THUẦN: không chạm database, không đọc `now()` ngầm, cùng khuôn với
 * `SrsScheduler` để unit test chạy được mà không dựng cả framework.
 *
 * `StatsSummaryService::memoryRate()` GỌI class này chứ không tự làm phép chia.
 * Trước đó hai chỗ có hai bản của cùng một công thức, chỉ được canh giữ bằng
 * một test đối chiếu — và một test đối chiếu chỉ phát hiện được sự trôi dạt sau
 * khi nó đã xảy ra. Điểm phiên và tỉ lệ nhớ mà lệch nhau trên hai màn là bug
 * sản phẩm, không phải sai số.
 */
final class ReviewScore
{
    public const GRADE_EXCELLENT = 'excellent';

    public const GRADE_GOOD = 'good';

    public const GRADE_FAIR = 'fair';

    public const GRADE_NEEDS_WORK = 'needs_work';

    private const THRESHOLD_EXCELLENT = 90;

    private const THRESHOLD_GOOD = 75;

    private const THRESHOLD_FAIR = 50;

    /**
     * Tỉ lệ đúng trên LƯỢT ĐẦU, làm tròn về số nguyên phần trăm.
     *
     * Cả hai tham số phải đã loại `is_retry` trước khi vào đây — quy ước
     * "lượt làm lại bị loại khỏi MỌI số liệu" (P14, red team H3) thuộc về nơi
     * đếm, không thuộc về công thức.
     */
    public function score(int $answered, int $correct): int
    {
        if ($answered <= 0) {
            return 0;
        }

        return (int) round($correct / $answered * 100);
    }

    /**
     * Xếp loại, hoặc NULL khi chưa trả lời câu nào.
     *
     * `null` chứ không phải `needs_work`: một phiên 0 lượt không phải làm kém,
     * nó không có gì để xếp loại. Trả `needs_work` ở đây sẽ khiến người dùng mở
     * trang ôn rồi thoát thấy mình bị chấm "Cần ôn thêm".
     */
    public function grade(int $score, int $answered): ?string
    {
        if ($answered <= 0) {
            return null;
        }

        return match (true) {
            $score >= self::THRESHOLD_EXCELLENT => self::GRADE_EXCELLENT,
            $score >= self::THRESHOLD_GOOD => self::GRADE_GOOD,
            $score >= self::THRESHOLD_FAIR => self::GRADE_FAIR,
            default => self::GRADE_NEEDS_WORK,
        };
    }
}
