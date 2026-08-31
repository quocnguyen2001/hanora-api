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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
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

/*
 * Helper của lớp search AI, đặt ở đây vì HAI file test dùng chung
 * (`SearchAiLayerTest` và `SearchRefineTest`) — cùng lý do các helper chuỗi ngày
 * ở trên đã nêu. Hai file đó phải dựng cùng một corpus và giả lập Gemini bằng
 * cùng một tay, nếu không thì "AI có được gọi không" ở hai bên không so được.
 */

/** Corpus mẫu + khoá Gemini giả + user đã đăng nhập, dùng trong `beforeEach`. */
function seedSearchFixtures(): void
{
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);
    Artisan::call('han-viet:import', [
        '--unihan' => base_path('tests/Fixtures/unihan-sample.txt'),
        '--supplement' => base_path('tests/Fixtures/hanviet-supplement-sample.csv'),
    ]);

    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-3.1-flash-lite']);
    test()->user = User::factory()->create();
}

/**
 * Giả lập một phản hồi Gemini thành công.
 *
 * @param  list<string>  $words
 * @param  array{zh: string, pinyin: string, vi: string}|null  $translation
 */
function aiReturns(array $words, ?array $translation = null): void
{
    $payload = ['words' => $words];

    if ($translation !== null) {
        $payload['translation'] = $translation;
    }

    Http::fake(['*' => Http::response([
        'usage' => ['total_input_tokens' => 40, 'total_output_tokens' => 20],
        'steps' => [
            ['type' => 'thought', 'signature' => 'x'],
            ['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => json_encode($payload)],
            ]],
        ],
    ])]);
}

/**
 * `null` tự rụng khỏi query string nhờ `array_filter` — `mode=` hay `refine=`
 * rỗng sẽ trượt `Rule::in` phía API và trả 422, đúng thứ ta KHÔNG muốn test
 * vô tình dựng lên.
 */
function searchApi(string $q, ?string $mode = 'vi', int $page = 1, ?string $refine = null): TestResponse
{
    $query = array_filter(['q' => $q, 'mode' => $mode, 'page' => $page, 'refine' => $refine]);

    return test()->actingAs(test()->user, 'sanctum')
        ->getJson('/api/dictionary/search?'.http_build_query($query));
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
