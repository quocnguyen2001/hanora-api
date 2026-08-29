<?php

declare(strict_types=1);

use App\Models\ReviewSession;
use App\Services\Review\ReviewScore;

it('sinh phiên hợp lệ với finished_at không bao giờ sớm hơn started_at', function (): void {
    // Một factory sinh ra thời lượng âm sẽ làm test `duration_seconds >= 0`
    // xanh vì lý do sai — nó không bao giờ gặp trường hợp thật.
    $sessions = ReviewSession::factory()->count(20)->create();

    foreach ($sessions as $session) {
        expect($session->finished_at)->not->toBeNull()
            ->and($session->finished_at->greaterThanOrEqualTo($session->started_at))->toBeTrue();
    }
});

it('tính thời lượng DƯƠNG, không phụ thuộc chiều diff của Carbon', function (): void {
    /*
     * Hồi quy cho một bug im lặng: Carbon 3 trả `diffIn*()` CÓ DẤU theo chiều
     * `$this -> $argument`, nên `$finished->diffInSeconds($started)` cho ra số
     * âm với MỌI phiên hợp lệ — và lịch sử ôn sẽ hiện "-8 phút" trên từng dòng.
     * Test viết theo cùng công thức sai sẽ xanh; chỉ người dùng thấy.
     */
    $session = ReviewSession::factory()->create([
        'started_at' => now()->subMinutes(8),
        'finished_at' => now(),
    ]);

    expect($session->durationSeconds())->toBe(480);
});

it('không có thời lượng khi phiên còn mở', function (): void {
    $session = ReviewSession::factory()->open()->create();

    expect($session->durationSeconds())->toBeNull()
        ->and($session->isOpen())->toBeTrue();
});

it('sinh phiên rỗng để kiểm đường dọn dẹp', function (): void {
    // `finishStale()` phải XOÁ phiên kiểu này, không phải chốt điểm 0 cho nó.
    $session = ReviewSession::factory()->empty()->create();

    expect($session->answered_count)->toBe(0)
        ->and($session->isOpen())->toBeTrue()
        ->and($session->grade)->toBeNull();
});

it('sinh grade khớp với score của chính nó', function (): void {
    $sessions = ReviewSession::factory()->count(20)->create();
    $scorer = new ReviewScore;

    foreach ($sessions as $session) {
        expect($session->grade)
            ->toBe($scorer->grade((int) $session->score, $session->answered_count));
    }
});
