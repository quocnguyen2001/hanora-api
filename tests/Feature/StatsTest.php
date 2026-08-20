<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\UserWord;
use App\Services\Review\AnswerGrader;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
    $this->words = DictionaryWord::factory()->count(10)->create();
});

function logReview(User $user, UserWord $userWord, bool $correct, string $when, bool $isRetry = false): ReviewLog
{
    return ReviewLog::create([
        'user_id' => $user->id,
        'user_word_id' => $userWord->id,
        'mode' => AnswerGrader::MODE_TYPING,
        'is_correct' => $correct,
        'is_retry' => $isRetry,
        'answer_raw' => 'x',
        'interval_before' => 0,
        'interval_after' => 1,
        'answered_at' => CarbonImmutable::parse($when, 'Asia/Ho_Chi_Minh'),
    ]);
}

function makeUserWord(User $user, int $index = 0, string $status = UserWord::STATUS_NEW): UserWord
{
    return UserWord::create([
        'user_id' => $user->id,
        'word_id' => test()->words[$index]->id,
        'status' => $status,
    ]);
}

function summary(string $range = 'week', ?User $as = null): array
{
    return test()->actingAs($as ?? test()->user, 'sanctum')
        ->getJson('/api/stats/summary?range='.$range)
        ->json('data');
}

describe('hợp đồng response', function (): void {
    it('trả đủ trường cho màn P17', function (): void {
        expect(array_keys(summary()))->toBe([
            'range', 'words_learned', 'words_learned_delta_pct', 'reviews_count',
            'streak_days', 'memory_rate', 'series', 'distribution',
        ]);
    });

    it('trả 401 khi không đăng nhập', function (): void {
        $this->getJson('/api/stats/summary')->assertUnauthorized();
    });

    it('từ chối range lạ', function (): void {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/stats/summary?range=decade')
            ->assertStatus(422)->assertJsonValidationErrors('range');
    });

    it('đặt private, no-store', function (): void {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/stats/summary')
            ->assertHeader('Cache-Control', 'no-store, private');
    });
});

describe('loại lượt làm lại — red team H3', function (): void {
    it('KHÔNG tính is_retry vào tỉ lệ nhớ', function (): void {
        // Người sai 1/2 rồi sửa lại đúng phải báo 50%, không phải 67%. Đếm cả
        // lượt sửa sẽ khiến người càng chăm sửa lỗi càng bị báo tỉ lệ thấp.
        $userWord = makeUserWord($this->user);

        logReview($this->user, $userWord, true, 'now');
        logReview($this->user, $userWord, false, 'now');
        logReview($this->user, $userWord, true, 'now', isRetry: true);

        expect(summary()['memory_rate'])->toBe(50);
    });

    it('KHÔNG tính is_retry vào reviews_count', function (): void {
        $userWord = makeUserWord($this->user);

        logReview($this->user, $userWord, true, 'now');
        logReview($this->user, $userWord, true, 'now', isRetry: true);

        expect(summary()['reviews_count'])->toBe(1);
    });

    it('trả 0 khi chưa có lượt ôn nào', function (): void {
        expect(summary()['memory_rate'])->toBe(0)
            ->and(summary()['reviews_count'])->toBe(0);
    });
});

describe('streak', function (): void {
    it('đếm số ngày liên tiếp tính đến hôm nay', function (): void {
        $userWord = makeUserWord($this->user);
        $today = CarbonImmutable::now('Asia/Ho_Chi_Minh');

        foreach ([0, 1, 2] as $daysAgo) {
            logReview($this->user, $userWord, true, $today->subDays($daysAgo)->format('Y-m-d H:i:s'));
        }

        expect(summary()['streak_days'])->toBe(3);
    });

    it('đứt chuỗi khi có ngày trống ở giữa', function (): void {
        $userWord = makeUserWord($this->user);
        $today = CarbonImmutable::now('Asia/Ho_Chi_Minh');

        logReview($this->user, $userWord, true, $today->format('Y-m-d H:i:s'));
        logReview($this->user, $userWord, true, $today->subDays(3)->format('Y-m-d H:i:s'));

        expect(summary()['streak_days'])->toBe(1);
    });

    it('giữ chuỗi khi hôm nay chưa ôn nhưng hôm qua có', function (): void {
        // Chưa ôn hôm nay không phải là đã đứt chuỗi — ngày vẫn còn chạy.
        $userWord = makeUserWord($this->user);
        $today = CarbonImmutable::now('Asia/Ho_Chi_Minh');

        logReview($this->user, $userWord, true, $today->subDay()->format('Y-m-d H:i:s'));
        logReview($this->user, $userWord, true, $today->subDays(2)->format('Y-m-d H:i:s'));

        expect(summary()['streak_days'])->toBe(2);
    });

    it('về 0 khi lượt ôn gần nhất đã quá xa', function (): void {
        $userWord = makeUserWord($this->user);

        logReview($this->user, $userWord, true, CarbonImmutable::now('Asia/Ho_Chi_Minh')->subDays(5)->format('Y-m-d H:i:s'));

        expect(summary()['streak_days'])->toBe(0);
    });

    it('tính ngày theo giờ VIỆT NAM, không phải UTC', function (): void {
        // Ôn lúc 6h sáng giờ VN = 23h hôm trước theo UTC. Gộp theo UTC sẽ đẩy
        // lượt này sang ngày hôm trước và làm streak reset lúc 7h sáng mỗi ngày.
        $userWord = makeUserWord($this->user);
        $today = CarbonImmutable::now('Asia/Ho_Chi_Minh');

        logReview($this->user, $userWord, true, $today->setTime(6, 0)->format('Y-m-d H:i:s'));

        expect(summary()['streak_days'])->toBe(1);
    });
});

describe('cô lập theo user', function (): void {
    it('không tính log của người khác', function (): void {
        $mine = makeUserWord($this->user);
        $theirs = UserWord::create(['user_id' => $this->other->id, 'word_id' => $this->words[1]->id]);

        logReview($this->user, $mine, true, 'now');
        logReview($this->other, $theirs, true, 'now');
        logReview($this->other, $theirs, true, 'now');

        expect(summary()['reviews_count'])->toBe(1);
    });
});

describe('giữ lịch sử của từ đã xóa — red team H5', function (): void {
    it('vẫn tính log của user_word đã soft-delete', function (): void {
        // Người dùng đã thực sự ôn những từ đó. Xóa một từ khỏi kho không được
        // phép viết lại lịch sử và làm bay chuỗi 60 ngày.
        $userWord = makeUserWord($this->user);
        logReview($this->user, $userWord, true, 'now');

        $userWord->delete();

        expect(summary()['reviews_count'])->toBe(1)
            ->and(summary()['streak_days'])->toBe(1);
    });
});

describe('words_learned và phân bố', function (): void {
    it('đếm từ ở reviewing và mastered', function (): void {
        makeUserWord($this->user, 0, UserWord::STATUS_NEW);
        makeUserWord($this->user, 1, UserWord::STATUS_LEARNING);
        makeUserWord($this->user, 2, UserWord::STATUS_REVIEWING);
        makeUserWord($this->user, 3, UserWord::STATUS_MASTERED);

        expect(summary()['words_learned'])->toBe(2);
    });

    it('gộp learning và reviewing thành Đang học trong phân bố', function (): void {
        // Cùng bảng map với P11/P12/P14 — `UserWord::TAB_STATUSES`.
        makeUserWord($this->user, 0, UserWord::STATUS_NEW);
        makeUserWord($this->user, 1, UserWord::STATUS_LEARNING);
        makeUserWord($this->user, 2, UserWord::STATUS_REVIEWING);
        makeUserWord($this->user, 3, UserWord::STATUS_MASTERED);

        expect(summary()['distribution'])->toBe(['new' => 25, 'learning' => 50, 'mastered' => 25]);
    });

    it('trả 0 hết khi kho rỗng, không chia cho 0', function (): void {
        expect(summary()['distribution'])->toBe(['new' => 0, 'learning' => 0, 'mastered' => 0]);
    });
});

describe('series cho biểu đồ', function (): void {
    it('điền đủ mọi ngày kể cả ngày không ôn', function (): void {
        // Biểu đồ có khoảng trống sẽ vẽ sai hình dạng thói quen học.
        $series = summary('week')['series'];

        expect($series)->toHaveCount(7)
            ->and(collect($series)->pluck('value')->unique()->all())->toBe([0]);
    });

    it('đếm đúng số lượt của từng ngày', function (): void {
        $userWord = makeUserWord($this->user);
        $today = CarbonImmutable::now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s');

        logReview($this->user, $userWord, true, $today);
        logReview($this->user, $userWord, false, $today);

        expect(collect(summary('week')['series'])->last()['value'])->toBe(2);
    });

    it('cắt còn tối đa 30 điểm cho kỳ dài', function (): void {
        expect(summary('year')['series'])->toHaveCount(30);
    });
});
