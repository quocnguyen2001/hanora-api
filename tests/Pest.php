<?php

declare(strict_types=1);

use App\Models\ReviewLog;
use App\Models\ReviewSession;
use App\Models\User;
use App\Models\UserWord;
use App\Services\Review\AnswerGrader;
use App\Services\Streak\StreakService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Bộ đếm throttle sống trong cache, và driver `array` giữ nguyên trạng
        // thái suốt cả process test. Không dọn thì một test chạm trần rate
        // limit sẽ làm mọi test sau nó nhận 429 — lỗi trông như của test khác,
        // ở file khác.
        Cache::flush();
    })
    ->in('Feature');

/*
 * Helper của chuỗi ngày, đặt ở đây vì HAI file test dùng chung (`StreakTest` và
 * `StreakRebuildTest`) — và bất biến quan trọng nhất của tính năng là hai file
 * đó so kết quả của nhau, nên chúng buộc phải dựng dữ liệu bằng cùng một tay.
 *
 * Helper riêng của một file thì vẫn để trong file đó, theo đúng thói quen của
 * `StatsTest`.
 */

function vnDay(int $daysAgo = 0): CarbonImmutable
{
    return CarbonImmutable::now('Asia/Ho_Chi_Minh')->startOfDay()->subDays($daysAgo);
}

/** Thêm `$count` từ mới vào kho, ghi `created_at` vào đúng ngày chỉ định. */
function addWords(User $user, int $count, CarbonImmutable $day, int $offset = 0): void
{
    for ($i = 0; $i < $count; $i++) {
        $word = UserWord::create([
            'user_id' => $user->id,
            'word_id' => test()->words[$offset + $i]->id,
            'status' => UserWord::STATUS_NEW,
        ]);

        // `created_at` do timestamps tự ghi — ép về ngày cần test.
        $word->forceFill(['created_at' => $day->setTime(10, 0)])->saveQuietly();
    }
}

/**
 * Một phiên ôn đã chốt, có `$logs` lượt trả lời.
 *
 * `$logs = 0` dựng đúng cái bẫy mà D4 nói tới: `finish()` chốt được phiên không
 * có lượt nào, và nếu luật chuỗi tin `finished_at` thay vì `review_logs` thì mở
 * phiên rồi bấm kết thúc là ăn chuỗi miễn phí.
 */
function finishedSessionOn(User $user, CarbonImmutable $day, int $logs = 1): ReviewSession
{
    $session = ReviewSession::create([
        'user_id' => $user->id,
        'mode' => AnswerGrader::MODE_TYPING,
        'source' => 'due',
        'planned_count' => 10,
        'started_at' => $day->setTime(20, 0),
        'finished_at' => $day->setTime(20, 30),
    ]);

    for ($i = 0; $i < $logs; $i++) {
        $userWord = UserWord::create([
            'user_id' => $user->id,
            'word_id' => unusedWordId($user),
        ]);

        ReviewLog::create([
            'user_id' => $user->id,
            'user_word_id' => $userWord->id,
            'review_session_id' => $session->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'is_correct' => true,
            'is_retry' => false,
            'answer_raw' => 'x',
            'interval_before' => 0,
            'interval_after' => 1,
            'answered_at' => $day->setTime(20, 10),
        ]);
    }

    return $session;
}

function streak(): StreakService
{
    return app(StreakService::class);
}

/**
 * Một `word_id` mà user này chưa từng lưu.
 *
 * `user_words` có unique index trên `(user_id, word_id)` cho bản ghi còn sống,
 * nên helper nào cũng phải tự tránh trùng — một test dựng nhiều phiên sẽ nổ ở
 * phiên thứ hai nếu cứ lấy cùng một chỉ số trong pool.
 */
function unusedWordId(User $user): int
{
    $taken = UserWord::withTrashed()->where('user_id', $user->id)->pluck('word_id')->all();

    $word = test()->words->firstWhere(fn ($w): bool => ! in_array($w->id, $taken, true));

    expect($word)->not->toBeNull('pool từ điển trong test đã cạn — tăng số lượng ở beforeEach');

    return $word->id;
}
