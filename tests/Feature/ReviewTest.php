<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\UserWord;
use App\Services\Review\AnswerGrader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

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
    $this->other = User::factory()->create();

    // Đủ từ hợp lệ để sinh được 3 distractor.
    DictionaryWord::factory()->count(10)->create(['is_priority' => true]);

    $this->word = DictionaryWord::where('simplified', '学习')->sole();
    $this->userWord = UserWord::create([
        'user_id' => $this->user->id,
        'word_id' => $this->word->id,
    ]);
});

function submitAnswer(array $payload, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? test()->user, 'sanctum')
        ->postJson('/api/reviews/answers', $payload);
}

describe('phiên ôn tập', function (): void {
    it('chỉ trả từ tới hạn hoặc từ mới', function (): void {
        UserWord::create([
            'user_id' => $this->user->id,
            'word_id' => DictionaryWord::where('simplified', '银行')->value('id'),
            'next_review_at' => now()->addDays(5),
        ]);

        $items = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/reviews/session?mode=typing&limit=10')->json('data.items');

        expect($items)->toHaveCount(1)
            ->and($items[0]['user_word_id'])->toBe($this->userWord->id);
    });

    it('KHÔNG đưa từ chưa ghép được âm Hán-Việt vào phiên', function (): void {
        // Câu hỏi không có âm Hán-Việt không phải câu hỏi khó — nó là câu hỏi
        // hỏng, cả hai mode đều xoay quanh âm đó (D13).
        $this->word->update(['han_viet' => null, 'han_viet_status' => DictionaryWord::STATUS_MISSING]);

        $items = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/reviews/session?mode=typing')->json('data.items');

        expect($items)->toBeEmpty();
    });

    it('trả 422 khi limit vượt ngưỡng', function (): void {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/reviews/session?mode=mcq&limit=100000')
            ->assertStatus(422)->assertJsonValidationErrors('limit');
    });

    it('trả 422 với mode lạ', function (): void {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/reviews/session?mode=flashcard')
            ->assertStatus(422)->assertJsonValidationErrors('mode');
    });

    it('trả 401 khi không đăng nhập', function (): void {
        $this->getJson('/api/reviews/session?mode=mcq')->assertUnauthorized();
    });
});

describe('payload trắc nghiệm', function (): void {
    it('có đúng 4 lựa chọn, mỗi lựa chọn mang word_id', function (): void {
        $items = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/reviews/session?mode=mcq')->json('data.items');

        expect($items[0]['options'])->toHaveCount(4);

        foreach ($items[0]['options'] as $option) {
            expect($option)->toHaveKeys(['word_id', 'text'])
                ->and($option['text'])->toBeString()->not->toBeEmpty();
        }
    });

    it('KHÔNG đánh dấu lựa chọn nào là đáp án đúng', function (): void {
        // Nếu payload lộ đáp án thì bài kiểm tra vô nghĩa.
        $items = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/reviews/session?mode=mcq')->json('data.items');

        foreach ($items[0]['options'] as $option) {
            expect(array_keys($option))->toBe(['word_id', 'text']);
        }
    });

    it('có đủ 3 distractor khác đáp án đúng', function (): void {
        $items = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/reviews/session?mode=mcq')->json('data.items');

        $texts = collect($items[0]['options'])->pluck('text');

        expect($texts->unique())->toHaveCount(4)
            ->and($texts)->toContain('học tập');
    });

    it('không kèm chữ Hán trong mode gõ', function (): void {
        // Chữ Hán chính là câu trả lời.
        $items = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/reviews/session?mode=typing')->json('data.items');

        expect($items[0])->toHaveKeys(['user_word_id', 'prompt_han_viet', 'hint'])
            ->and($items[0]['prompt_han_viet'])->toBe('học tập')
            ->and($items[0])->not->toHaveKey('word');
    });
});

describe('chấm bài trắc nghiệm', function (): void {
    it('chấm đúng bằng answer_word_id, không cần state phiên', function (): void {
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_MCQ,
            'answer_word_id' => $this->word->id,
        ])->assertOk()->assertJsonPath('data.correct', true);
    });

    it('chấm sai khi chọn word_id khác', function (): void {
        $wrong = DictionaryWord::where('id', '!=', $this->word->id)->value('id');

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_MCQ,
            'answer_word_id' => $wrong,
        ])->assertOk()->assertJsonPath('data.correct', false);
    });
});

describe('chấm bài mode gõ', function (): void {
    it('chấp nhận chữ Hán, phồn thể và pinyin', function (string $answer): void {
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => $answer,
        ])->assertOk()->assertJsonPath('data.correct', true);
    })->with([
        'giản thể' => ['学习'],
        'phồn thể' => ['學習'],
        'pinyin không dấu' => ['xuexi'],
        'pinyin có dấu' => ['xuéxí'],
        'pinyin có cách' => ['xue xi'],
        'thừa khoảng trắng' => ['  学习  '],
    ]);

    it('chấm sai với câu trả lời khác', function (): void {
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '银行',
        ])->assertOk()->assertJsonPath('data.correct', false);
    });

    it('từ chối câu trả lời dài quá 64 ký tự', function (): void {
        // Không giới hạn thì một chuỗi 1MB lặp lại đủ để lấp đĩa VPS.
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => str_repeat('a', 65),
        ])->assertStatus(422)->assertJsonValidationErrors('answer');
    });
});

describe('IDOR — red team H1', function (): void {
    it('user B nộp bài cho user_word của A: 404, không ghi log, không đổi lịch', function (): void {
        $before = $this->userWord->fresh();

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ], as: $this->other)->assertNotFound();

        $after = $this->userWord->fresh();

        expect(ReviewLog::count())->toBe(0)
            ->and($after->next_review_at)->toEqual($before->next_review_at)
            ->and($after->review_count)->toBe($before->review_count)
            ->and($after->interval_days)->toBe($before->interval_days);
    });

    it('trả 404 cho user_word_id không tồn tại', function (): void {
        submitAnswer([
            'user_word_id' => 999999,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertNotFound();
    });
});

describe('lịch ôn sau khi nộp', function (): void {
    it('trả lời đúng đặt lịch 1 ngày cho lần đầu', function (): void {
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertOk();

        $fresh = $this->userWord->fresh();

        expect($fresh->interval_days)->toBe(1)
            ->and($fresh->repetitions)->toBe(1)
            ->and($fresh->review_count)->toBe(1)
            ->and($fresh->correct_count)->toBe(1)
            ->and($fresh->status)->toBe(UserWord::STATUS_LEARNING);
    });

    it('trả lời sai đặt lại lịch về 0', function (): void {
        $this->userWord->update(['repetitions' => 4, 'interval_days' => 20]);

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => 'sai',
        ])->assertOk();

        $fresh = $this->userWord->fresh();

        expect($fresh->interval_days)->toBe(0)
            ->and($fresh->repetitions)->toBe(0)
            ->and($fresh->correct_count)->toBe(0);
    });

    it('ghi log mỗi lượt', function (): void {
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertOk();

        $log = ReviewLog::sole();

        expect($log->is_correct)->toBeTrue()
            ->and($log->is_retry)->toBeFalse()
            ->and($log->answer_raw)->toBe('学习')
            ->and($log->interval_before)->toBe(0)
            ->and($log->interval_after)->toBe(1);
    });
});

describe('làm lại trong phiên — red team H3', function (): void {
    it('lượt retry được ghi log nhưng KHÔNG đổi lịch', function (): void {
        // Sai trước.
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => 'sai',
        ])->assertOk();

        $afterWrong = $this->userWord->fresh();

        // Sửa lại đúng trong cùng phiên.
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
            'is_retry' => true,
        ])->assertOk()->assertJsonPath('data.correct', true);

        $afterRetry = $this->userWord->fresh();

        // Hình phạt SRS phải còn nguyên: nếu retry chạy scheduler thì sai-rồi-
        // sửa sẽ cho ra cùng lịch như đúng ngay từ đầu.
        expect($afterRetry->interval_days)->toBe($afterWrong->interval_days)
            ->and($afterRetry->repetitions)->toBe($afterWrong->repetitions)
            ->and($afterRetry->ease_factor)->toBe($afterWrong->ease_factor)
            ->and($afterRetry->review_count)->toBe($afterWrong->review_count);
    });

    it('đánh dấu is_retry trong log để P16 loại khỏi tỉ lệ nhớ', function (): void {
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
            'is_retry' => true,
        ])->assertOk();

        expect(ReviewLog::sole()->is_retry)->toBeTrue();
    });

    it('sai rồi sửa KHÔNG cho ra lịch giống đúng ngay lần đầu', function (): void {
        // Success Criteria của P14, viết thẳng thành test.
        $wrongThenRight = UserWord::create([
            'user_id' => $this->user->id,
            'word_id' => DictionaryWord::where('simplified', '银行')->value('id'),
        ]);

        submitAnswer([
            'user_word_id' => $wrongThenRight->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => 'sai',
        ]);
        submitAnswer([
            'user_word_id' => $wrongThenRight->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '银行',
            'is_retry' => true,
        ]);

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ]);

        expect($wrongThenRight->fresh()->interval_days)
            ->not->toBe($this->userWord->fresh()->interval_days);
    });
});

describe('log sống sót khi xóa từ khỏi kho — red team H5', function (): void {
    it('giữ nguyên review_logs sau khi soft-delete user_word', function (): void {
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertOk();

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/vocabulary/{$this->userWord->id}")
            ->assertNoContent();

        // P16 tính streak và tỉ lệ nhớ trên MỌI log — người dùng đã thực sự ôn
        // những từ này, kể cả từ sau đó họ bỏ khỏi kho.
        expect(ReviewLog::count())->toBe(1);
    });
});
