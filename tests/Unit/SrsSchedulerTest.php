<?php

declare(strict_types=1);

use App\Models\UserWord;
use App\Services\Review\SrsScheduler;
use Carbon\CarbonImmutable;

function userWord(int $repetitions = 0, int $interval = 0, float $ease = 2.50): UserWord
{
    $word = new UserWord;
    $word->repetitions = $repetitions;
    $word->interval_days = $interval;
    $word->ease_factor = $ease;

    return $word;
}

function answeredAt(string $vietnamLocalTime): CarbonImmutable
{
    return CarbonImmutable::parse($vietnamLocalTime, 'Asia/Ho_Chi_Minh');
}

beforeEach(function (): void {
    $this->scheduler = new SrsScheduler;
});

describe('trả lời đúng', function (): void {
    it('đặt lịch 1 ngày cho lần đúng đầu tiên', function (): void {
        $result = $this->scheduler->schedule(userWord(), true, answeredAt('2026-08-20 10:00'));

        expect($result['interval_days'])->toBe(1)
            ->and($result['repetitions'])->toBe(1);
    });

    it('đặt lịch 3 ngày cho lần đúng thứ hai', function (): void {
        $result = $this->scheduler->schedule(userWord(1, 1), true, answeredAt('2026-08-20 10:00'));

        expect($result['interval_days'])->toBe(3)
            ->and($result['repetitions'])->toBe(2);
    });

    it('nhân khoảng cách với ease_factor từ lần thứ ba', function (): void {
        // 3 ngày * 2.50 = 7.5 -> làm tròn 8
        $result = $this->scheduler->schedule(userWord(2, 3), true, answeredAt('2026-08-20 10:00'));

        expect($result['interval_days'])->toBe(8)
            ->and($result['repetitions'])->toBe(3);
    });

    it('thưởng ease_factor nhưng không vượt trần 2.80', function (): void {
        expect($this->scheduler->schedule(userWord(1, 1, 2.50), true, answeredAt('2026-08-20 10:00'))['ease_factor'])
            ->toBe(2.55)
            ->and($this->scheduler->schedule(userWord(1, 1, 2.80), true, answeredAt('2026-08-20 10:00'))['ease_factor'])
            ->toBe(2.80);
    });
});

describe('trả lời sai', function (): void {
    it('đặt lại repetitions và interval về 0', function (): void {
        $result = $this->scheduler->schedule(userWord(5, 30, 2.60), false, answeredAt('2026-08-20 10:00'));

        expect($result['repetitions'])->toBe(0)
            ->and($result['interval_days'])->toBe(0);
    });

    it('phạt ease_factor nhưng không xuống dưới sàn 1.30', function (): void {
        expect($this->scheduler->schedule(userWord(3, 10, 2.50), false, answeredAt('2026-08-20 10:00'))['ease_factor'])
            ->toBe(2.30)
            ->and($this->scheduler->schedule(userWord(3, 10, 1.30), false, answeredAt('2026-08-20 10:00'))['ease_factor'])
            ->toBe(1.30);
    });

    it('cho từ vừa sai tới hạn NGAY, không phải đầu ngày mai', function (): void {
        // Từ vừa sai phải quay lại trong chính phiên đang học.
        $moment = answeredAt('2026-08-20 21:30');
        $result = $this->scheduler->schedule(userWord(3, 10), false, $moment);

        expect($result['next_review_at']->equalTo($moment))->toBeTrue();
    });
});

describe('chuyển đổi status', function (): void {
    it('vào learning khi khoảng cách còn ngắn', function (): void {
        expect($this->scheduler->schedule(userWord(), true, answeredAt('2026-08-20 10:00'))['status'])
            ->toBe(UserWord::STATUS_LEARNING);
    });

    it('vào reviewing từ 7 ngày', function (): void {
        // 3 ngày * 2.50 = 8 >= 7
        expect($this->scheduler->schedule(userWord(2, 3), true, answeredAt('2026-08-20 10:00'))['status'])
            ->toBe(UserWord::STATUS_REVIEWING);
    });

    it('vào mastered khi đủ 30 ngày VÀ 3 lần đúng liên tiếp', function (): void {
        // 15 ngày * 2.50 = 37.5 -> 38 >= 30, repetitions 4 >= 3
        expect($this->scheduler->schedule(userWord(3, 15), true, answeredAt('2026-08-20 10:00'))['status'])
            ->toBe(UserWord::STATUS_MASTERED);
    });

    it('KHÔNG vào mastered nếu đủ ngày nhưng chưa đủ lần đúng', function (): void {
        // Một cú nhảy khoảng cách đơn lẻ không chứng minh là đã nhớ.
        $result = $this->scheduler->schedule(userWord(1, 1), true, answeredAt('2026-08-20 10:00'));

        expect($result['status'])->not->toBe(UserWord::STATUS_MASTERED);
    });

    it('rơi khỏi mastered khi trả lời sai', function (): void {
        expect($this->scheduler->schedule(userWord(5, 40), false, answeredAt('2026-08-20 10:00'))['status'])
            ->toBe(UserWord::STATUS_LEARNING);
    });
});

describe('ranh giới NGÀY theo giờ Việt Nam', function (): void {
    it('snap về 00:00 của ngày mục tiêu', function (): void {
        $result = $this->scheduler->schedule(userWord(), true, answeredAt('2026-08-20 21:30'));

        expect($result['next_review_at']->format('Y-m-d H:i'))->toBe('2026-08-21 00:00');
    });

    it('ôn lúc 21:30 thì 8:00 sáng hôm sau ĐÃ tới hạn', function (): void {
        // Đây là Success Criteria của P14. Nếu để chính xác theo đồng hồ thì
        // hạn rơi vào 21:30 hôm sau, và 8h sáng mở app sẽ báo hết từ dù người
        // dùng đã sang ngày mới từ lâu.
        $result = $this->scheduler->schedule(userWord(), true, answeredAt('2026-08-20 21:30'));

        expect($result['next_review_at']->lessThanOrEqualTo(answeredAt('2026-08-21 08:00')))->toBeTrue();
    });

    it('cho cùng một ngày mục tiêu dù ôn lúc 23:30 hay 00:30', function (): void {
        // Cặp test mà phase doc chỉ đích danh: hai thời điểm cách nhau một tiếng
        // nhưng nằm hai bên nửa đêm.
        $khuya = $this->scheduler->schedule(userWord(), true, answeredAt('2026-08-20 23:30'));
        $sangSom = $this->scheduler->schedule(userWord(), true, answeredAt('2026-08-21 00:30'));

        expect($khuya['next_review_at']->format('Y-m-d'))->toBe('2026-08-21')
            ->and($sangSom['next_review_at']->format('Y-m-d'))->toBe('2026-08-22');
    });

    it('tính ranh giới theo giờ Việt Nam chứ không phải UTC', function (): void {
        // 2026-08-20 23:30 giờ VN = 16:30 UTC cùng ngày. Nếu snap theo UTC thì
        // ra 2026-08-21 00:00 UTC = 07:00 sáng 21 giờ VN — lệch nửa ngày.
        $result = $this->scheduler->schedule(userWord(), true, answeredAt('2026-08-20 23:30'));

        expect($result['next_review_at']->timezone->getName())->toBe('Asia/Ho_Chi_Minh')
            ->and($result['next_review_at']->format('H:i'))->toBe('00:00');
    });
});
