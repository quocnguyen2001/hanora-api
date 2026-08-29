<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\ReviewLog;
use App\Models\ReviewSession;
use App\Models\User;
use App\Models\UserWord;
use App\Services\Review\AnswerGrader;
use App\Services\Review\ReviewSessionBuilder;
use App\Services\Review\ReviewSessionManager;
use App\Services\Review\WeakWordQuery;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);
    Artisan::call('han-viet:import', [
        '--unihan' => base_path('tests/Fixtures/unihan-sample.txt'),
        '--supplement' => base_path('tests/Fixtures/hanviet-supplement-sample.csv'),
    ]);

    $this->user = User::factory()->create();
    $this->manager = app(ReviewSessionManager::class);

    // Đủ từ hợp lệ để dựng được câu trắc nghiệm 4 lựa chọn.
    DictionaryWord::factory()->count(10)->create(['is_priority' => true]);

    $this->word = DictionaryWord::where('simplified', '学习')->sole();
    $this->userWord = UserWord::create([
        'user_id' => $this->user->id,
        'word_id' => $this->word->id,
    ]);
});

describe('mở phiên', function (): void {
    it('KHÔNG tạo bản ghi khi không có thẻ nào', function (): void {
        // Không có từ nào tới hạn cho user mới này.
        $stranger = User::factory()->create();

        $result = $this->manager->start($stranger, AnswerGrader::MODE_TYPING, ReviewSession::SOURCE_DUE, 10);

        expect($result['session'])->toBeNull()
            ->and($result['empty_reason'])->toBe(ReviewSessionBuilder::EMPTY_NO_WORDS)
            // Mở trang ôn rồi thoát không được để lại phiên rác trong lịch sử.
            ->and(ReviewSession::count())->toBe(0);
    });

    it('đặt planned_count theo số thẻ THỰC SỰ phát ra', function (): void {
        $result = $this->manager->start($this->user, AnswerGrader::MODE_TYPING, ReviewSession::SOURCE_DUE, 10);

        expect($result['session']->planned_count)->toBe(count($result['items']))
            ->and($result['session']->planned_count)->toBe(1);
    });

    it('XOÁ phiên bỏ dở chưa trả lời câu nào, không chốt điểm 0 cho nó', function (): void {
        $abandoned = ReviewSession::factory()->empty()->create(['user_id' => $this->user->id]);

        $this->manager->start($this->user, AnswerGrader::MODE_TYPING, ReviewSession::SOURCE_DUE, 10);

        expect(ReviewSession::find($abandoned->id))->toBeNull();
    });

    it('chốt phiên bỏ dở CÓ lượt trả lời thay vì xoá', function (): void {
        $abandoned = ReviewSession::factory()->open()->create([
            'user_id' => $this->user->id,
            'answered_count' => 3,
            'correct_count' => 2,
        ]);

        $this->manager->start($this->user, AnswerGrader::MODE_TYPING, ReviewSession::SOURCE_DUE, 10);

        expect($abandoned->fresh()->finished_at)->not->toBeNull();
    });

    it('không đụng phiên đang mở của người khác', function (): void {
        $other = User::factory()->create();
        $theirs = ReviewSession::factory()->open()->create([
            'user_id' => $other->id,
            'answered_count' => 1,
        ]);

        $this->manager->start($this->user, AnswerGrader::MODE_TYPING, ReviewSession::SOURCE_DUE, 10);

        expect($theirs->fresh()->finished_at)->toBeNull();
    });
});

describe('suy is_retry ở server', function (): void {
    it('lượt đầu của một từ trong phiên KHÔNG phải làm lại', function (): void {
        $session = ReviewSession::factory()->open()->create(['user_id' => $this->user->id]);

        expect($this->manager->isRetry($session, $this->userWord))->toBeFalse();
    });

    it('nhận ra lượt thứ hai của cùng từ trong cùng phiên', function (): void {
        $session = ReviewSession::factory()->open()->create(['user_id' => $this->user->id]);

        logAnswer($session, $this->userWord, isCorrect: false);

        expect($this->manager->isRetry($session, $this->userWord))->toBeTrue();
    });

    it('KHÔNG coi là làm lại khi cùng từ nhưng khác phiên', function (): void {
        // Ôn lại một từ ở phiên hôm sau là lượt đầu của phiên đó, không phải
        // sửa lỗi trong cùng buổi học.
        $yesterday = ReviewSession::factory()->create(['user_id' => $this->user->id]);
        $today = ReviewSession::factory()->open()->create(['user_id' => $this->user->id]);

        logAnswer($yesterday, $this->userWord, isCorrect: false);

        expect($this->manager->isRetry($today, $this->userWord))->toBeFalse();
    });
});

describe('hai cờ SRS tách rời', function (): void {
    it('phiên thường: lượt đầu chạy cả bộ đếm lẫn lịch', function (): void {
        $session = ReviewSession::factory()->open()->create([
            'user_id' => $this->user->id,
            'source' => ReviewSession::SOURCE_DUE,
        ]);

        expect($this->manager->shouldCount(isRetry: false))->toBeTrue()
            ->and($this->manager->shouldSchedule($session, isCorrect: true, isRetry: false))->toBeTrue();
    });

    it('phiên weak + trả lời ĐÚNG: đếm nhưng KHÔNG kéo dài lịch', function (): void {
        /*
         * Đây là bất biến quan trọng nhất của chế độ ôn từ sai. Nếu cờ đếm cũng
         * bị khoá: `review_count`/`correct_count` đóng băng, "số lần sai" suy ra
         * từ hiệu hai cột đó không bao giờ giảm, và từ đã thuộc lòng ở lại danh
         * sách "từ hay sai" vĩnh viễn.
         */
        $session = ReviewSession::factory()->open()->create([
            'user_id' => $this->user->id,
            'source' => ReviewSession::SOURCE_WEAK,
        ]);

        expect($this->manager->shouldCount(isRetry: false))->toBeTrue()
            ->and($this->manager->shouldSchedule($session, isCorrect: true, isRetry: false))->toBeFalse();
    });

    it('phiên weak + trả lời SAI: chạy lịch như thường', function (): void {
        // Quên thật thì là quên thật, bất kể phiên nào.
        $session = ReviewSession::factory()->open()->create([
            'user_id' => $this->user->id,
            'source' => ReviewSession::SOURCE_WEAK,
        ]);

        expect($this->manager->shouldSchedule($session, isCorrect: false, isRetry: false))->toBeTrue();
    });

    it('lượt làm lại không chạy cả hai, ở mọi nguồn', function (): void {
        foreach (ReviewSession::SOURCES as $source) {
            $session = ReviewSession::factory()->open()->create([
                'user_id' => $this->user->id,
                'source' => $source,
            ]);

            expect($this->manager->shouldCount(isRetry: true))->toBeFalse()
                ->and($this->manager->shouldSchedule($session, isCorrect: true, isRetry: true))->toBeFalse()
                ->and($this->manager->shouldSchedule($session, isCorrect: false, isRetry: true))->toBeFalse();
        }
    });
});

describe('chốt điểm', function (): void {
    it('tính điểm từ review_logs, KHÔNG tin bộ đếm', function (): void {
        /*
         * Bộ đếm bị làm lệch cố ý ở đây mô phỏng một lượt nộp commit sau khi
         * phiên đã chốt. Vì `finish()` idempotent, nếu nó tin bộ đếm thì phiên
         * sẽ mang vĩnh viễn một điểm không khớp log của chính nó.
         */
        $session = ReviewSession::factory()->open()->create([
            'user_id' => $this->user->id,
            'answered_count' => 99,
            'correct_count' => 99,
        ]);

        logAnswer($session, $this->userWord, isCorrect: true);
        logAnswer($session, $this->userWord, isCorrect: false, isRetry: true);
        logAnswer($session, $this->userWord, isCorrect: false);

        $this->manager->finish($session);

        expect($session->fresh()->answered_count)->toBe(2)   // lượt retry bị loại
            ->and($session->fresh()->correct_count)->toBe(1)
            ->and($session->fresh()->score)->toBe(50);
    });

    it('idempotent: gọi lần hai không đổi gì', function (): void {
        $session = ReviewSession::factory()->open()->create(['user_id' => $this->user->id]);
        logAnswer($session, $this->userWord, isCorrect: true);

        $first = $this->manager->finish($session)->fresh();
        $second = $this->manager->finish($session->fresh())->fresh();

        expect($second->score)->toBe($first->score)
            ->and($second->finished_at->equalTo($first->finished_at))->toBeTrue();
    });

    it('không xếp loại phiên không có lượt nào tính điểm', function (): void {
        $session = ReviewSession::factory()->open()->create(['user_id' => $this->user->id]);

        $this->manager->finish($session);

        expect($session->fresh()->grade)->toBeNull()
            ->and($session->fresh()->score)->toBe(0);
    });
});

describe('nguồn từ hay sai', function (): void {
    it('bỏ qua lịch tới hạn và chỉ lấy từ từng sai', function (): void {
        $weakWord = UserWord::create([
            'user_id' => $this->user->id,
            'word_id' => DictionaryWord::where('simplified', '银行')->value('id'),
            // CHƯA tới hạn — phiên `due` sẽ bỏ qua, phiên `weak` thì không.
            'next_review_at' => now()->addDays(5),
            'review_count' => 4,
            'correct_count' => 1,
        ]);

        $result = app(WeakWordQuery::class)->forUser($this->user, 10);

        expect($result->pluck('id')->all())->toBe([$weakWord->id]);
    });

    it('KHÔNG trả từ chưa từng sai', function (): void {
        UserWord::create([
            'user_id' => $this->user->id,
            'word_id' => DictionaryWord::where('simplified', '银行')->value('id'),
            'review_count' => 4,
            'correct_count' => 4,
        ]);

        expect(app(WeakWordQuery::class)->forUser($this->user, 10))->toBeEmpty();
    });

    it('sắp từ sai nhiều lên trước', function (): void {
        $words = DictionaryWord::query()
            ->whereIn('han_viet_status', [DictionaryWord::STATUS_OK, DictionaryWord::STATUS_MANUAL])
            ->whereNotNull('han_viet')
            ->where('id', '!=', $this->word->id)
            ->limit(2)
            ->pluck('id');

        $lessWrong = UserWord::create([
            'user_id' => $this->user->id, 'word_id' => $words[0],
            'review_count' => 5, 'correct_count' => 4,   // sai 1
        ]);
        $moreWrong = UserWord::create([
            'user_id' => $this->user->id, 'word_id' => $words[1],
            'review_count' => 9, 'correct_count' => 3,   // sai 6
        ]);

        $ordered = app(WeakWordQuery::class)->forUser($this->user, 10)->pluck('id')->all();

        expect($ordered)->toBe([$moreWrong->id, $lessWrong->id]);
    });
});

/** Ghi một lượt trả lời thẳng vào log — chỉ để dựng tình huống cho test đọc. */
function logAnswer(
    ReviewSession $session,
    UserWord $userWord,
    bool $isCorrect,
    bool $isRetry = false,
): void {
    ReviewLog::create([
        'user_id' => $userWord->user_id,
        'user_word_id' => $userWord->id,
        'review_session_id' => $session->id,
        'mode' => AnswerGrader::MODE_TYPING,
        'is_correct' => $isCorrect,
        'is_retry' => $isRetry,
        'answer_raw' => 'x',
        'interval_before' => 0,
        'interval_after' => 0,
        'answered_at' => now(),
    ]);
}
