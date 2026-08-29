<?php

declare(strict_types=1);

use App\Services\Review\ReviewScore;

beforeEach(function (): void {
    $this->score = new ReviewScore;
});

describe('score', function (): void {
    it('trả 0 khi chưa trả lời câu nào, không chia cho 0', function (): void {
        expect($this->score->score(0, 0))->toBe(0);
    });

    it('tính tỉ lệ đúng theo phần trăm, làm tròn', function (): void {
        expect($this->score->score(10, 10))->toBe(100)
            ->and($this->score->score(10, 0))->toBe(0)
            ->and($this->score->score(3, 2))->toBe(67)   // 66.67 -> 67
            ->and($this->score->score(3, 1))->toBe(33);  // 33.33 -> 33
    });
});

describe('grade', function (): void {
    /*
     * Mốc BIÊN, không phải giá trị giữa dải: một ngưỡng viết `>` thay vì `>=`
     * chỉ sai đúng ở đây.
     */
    it('xếp loại theo đúng ngưỡng biên', function (int $score, string $expected): void {
        expect($this->score->grade($score, 10))->toBe($expected);
    })->with([
        [100, ReviewScore::GRADE_EXCELLENT],
        [90, ReviewScore::GRADE_EXCELLENT],
        [89, ReviewScore::GRADE_GOOD],
        [75, ReviewScore::GRADE_GOOD],
        [74, ReviewScore::GRADE_FAIR],
        [50, ReviewScore::GRADE_FAIR],
        [49, ReviewScore::GRADE_NEEDS_WORK],
        [0, ReviewScore::GRADE_NEEDS_WORK],
    ]);

    it('KHÔNG xếp loại phiên chưa trả lời câu nào', function (): void {
        // `null` chứ không phải `needs_work`: mở trang ôn rồi thoát không phải
        // là làm kém. Trả `needs_work` ở đây sẽ chấm người dùng "Cần ôn thêm"
        // cho một phiên họ chưa làm gì.
        expect($this->score->grade(0, 0))->toBeNull();
    });
});
