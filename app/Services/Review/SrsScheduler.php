<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\UserWord;
use Carbon\CarbonImmutable;

/**
 * SM-2 rút gọn. Class THUẦN — không chạm database, không đọc `now()` ngầm.
 *
 * Tách riêng để sau này thay bằng FSRS chỉ cần đổi implementation và thêm cột,
 * không phải mổ controller.
 */
final class SrsScheduler
{
    private const EASE_FLOOR = 1.30;

    private const EASE_CEILING = 2.80;

    private const EASE_PENALTY = 0.20;

    private const EASE_REWARD = 0.05;

    /** `reviewing` bắt đầu từ đây. */
    private const REVIEWING_INTERVAL = 7;

    /** `mastered` cần khoảng cách này VÀ 3 lần đúng liên tiếp. */
    private const MASTERED_INTERVAL = 30;

    private const MASTERED_REPETITIONS = 3;

    /**
     * Múi giờ TIÊM VÀO, không đọc `config()` bên trong.
     *
     * Class này phải thuần để unit test chạy được mà không dựng cả framework —
     * và vì ranh giới ngày là thứ quan trọng nhất ở đây, nó phải test được dễ.
     * Binding thật nằm ở `AppServiceProvider`.
     */
    public function __construct(private readonly string $timezone = 'Asia/Ho_Chi_Minh') {}

    /**
     * @return array{
     *     interval_days: int,
     *     ease_factor: float,
     *     repetitions: int,
     *     status: string,
     *     next_review_at: CarbonImmutable
     * }
     */
    public function schedule(UserWord $userWord, bool $isCorrect, CarbonImmutable $answeredAt): array
    {
        $ease = (float) $userWord->ease_factor;
        $repetitions = $userWord->repetitions;
        $interval = $userWord->interval_days;

        if (! $isCorrect) {
            $repetitions = 0;
            $interval = 0;
            $ease = max(self::EASE_FLOOR, $ease - self::EASE_PENALTY);
        } else {
            $repetitions++;

            $interval = match (true) {
                $repetitions === 1 => 1,
                $repetitions === 2 => 3,
                default => (int) round($interval * $ease),
            };

            $ease = min(self::EASE_CEILING, $ease + self::EASE_REWARD);
        }

        return [
            'interval_days' => $interval,
            'ease_factor' => round($ease, 2),
            'repetitions' => $repetitions,
            'status' => $this->statusFor($interval, $repetitions),
            'next_review_at' => $this->nextReviewAt($answeredAt, $interval),
        ];
    }

    /**
     * Snap về 00:00 của NGÀY mục tiêu theo giờ Việt Nam.
     *
     * Ranh giới NGÀY, không phải đồng hồ. Nếu để chính xác theo đồng hồ: ôn lúc
     * 21:30 → hạn 21:30 hôm sau → 8h sáng mở app thấy báo hết từ, dù người dùng
     * đã sang ngày mới từ lâu. Tiêu chí "chỉ lấy từ tới hạn" vẫn đúng về kỹ
     * thuật trong khi sản phẩm hỏng.
     *
     * `interval_days = 0` (trả lời sai) tới hạn NGAY, không phải đầu ngày mai —
     * từ vừa sai phải quay lại trong chính phiên đang học.
     */
    public function nextReviewAt(CarbonImmutable $answeredAt, int $intervalDays): CarbonImmutable
    {
        if ($intervalDays <= 0) {
            return $answeredAt;
        }

        return $answeredAt
            ->setTimezone($this->timezone)
            ->addDays($intervalDays)
            ->startOfDay();
    }

    private function statusFor(int $intervalDays, int $repetitions): string
    {
        if ($intervalDays >= self::MASTERED_INTERVAL && $repetitions >= self::MASTERED_REPETITIONS) {
            return UserWord::STATUS_MASTERED;
        }

        if ($intervalDays >= self::REVIEWING_INTERVAL) {
            return UserWord::STATUS_REVIEWING;
        }

        return UserWord::STATUS_LEARNING;
    }
}
