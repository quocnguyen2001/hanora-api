<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\ReviewLog;
use App\Models\ReviewSession;
use App\Models\User;
use App\Models\UserWord;
use App\Services\Review\AnswerGrader;
use App\Services\Review\ReviewSessionBuilder;
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

/** Mở một phiên qua chính endpoint thật. */
function startSession(array $payload = [], ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? test()->user, 'sanctum')
        ->postJson('/api/reviews/sessions', array_merge(
            ['mode' => AnswerGrader::MODE_TYPING],
            $payload,
        ));
}

/** Id phiên vừa mở. */
function openSessionId(array $payload = []): int
{
    return (int) startSession($payload)->json('data.session.id');
}

function finishSession(int $id, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? test()->user, 'sanctum')
        ->postJson("/api/reviews/sessions/{$id}/finish");
}

/**
 * Nộp bài.
 *
 * KHÔNG có `is_retry` trong payload: server suy nó từ log của phiên. Muốn dựng
 * một lượt làm lại thì nộp cùng `user_word_id` lần thứ hai trong cùng phiên —
 * đúng như người dùng thật làm.
 */
function submitAnswer(array $payload, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? test()->user, 'sanctum')
        ->postJson('/api/reviews/answers', $payload);
}

describe('mở phiên ôn', function (): void {
    it('chỉ trả từ tới hạn hoặc từ mới', function (): void {
        UserWord::create([
            'user_id' => $this->user->id,
            'word_id' => DictionaryWord::where('simplified', '银行')->value('id'),
            'next_review_at' => now()->addDays(5),
        ]);

        $items = startSession()->assertCreated()->json('data.items');

        expect($items)->toHaveCount(1)
            ->and($items[0]['user_word_id'])->toBe($this->userWord->id);
    });

    it('KHÔNG đưa từ chưa ghép được âm Hán-Việt vào phiên', function (): void {
        // Câu hỏi không có âm Hán-Việt không phải câu hỏi khó — nó là câu hỏi
        // hỏng, cả hai mode đều xoay quanh âm đó (D13).
        $this->word->update(['han_viet' => null, 'han_viet_status' => DictionaryWord::STATUS_MISSING]);

        expect(startSession()->assertOk()->json('data.items'))->toBeEmpty();
    });

    it('KHÔNG tạo bản ghi phiên khi không có thẻ nào', function (): void {
        // Mở trang ôn lúc chưa tới hạn từ nào là thao tác bình thường; để lại
        // một phiên 0 điểm mỗi lần như thế sẽ đắp đầy lịch sử bằng rác.
        $this->word->update(['han_viet' => null, 'han_viet_status' => DictionaryWord::STATUS_MISSING]);

        startSession()->assertOk()
            ->assertJsonPath('data.session', null)
            ->assertJsonPath('data.empty_reason', ReviewSessionBuilder::EMPTY_NO_WORDS);

        expect(ReviewSession::count())->toBe(0);
    });

    it('phân biệt "không có từ" với "không dựng được câu trắc nghiệm"', function (): void {
        /*
         * Người dùng CÓ từ hay sai nhưng kho quá mỏng để sinh 3 distractor khác
         * âm. Báo "chưa có từ nào bạn từng sai" ở đây là nói sai sự thật, trong
         * khi trang Thống kê đang hiện đúng những từ đó.
         */
        DictionaryWord::query()->where('id', '!=', $this->word->id)->delete();
        $this->userWord->update(['review_count' => 3, 'correct_count' => 1]);

        startSession(['mode' => AnswerGrader::MODE_MCQ, 'source' => ReviewSession::SOURCE_WEAK])
            ->assertOk()
            ->assertJsonPath('data.empty_reason', ReviewSessionBuilder::EMPTY_NOT_ENOUGH_OPTIONS);
    });

    it('đặt Cache-Control private, no-store', function (): void {
        startSession()->assertHeader('Cache-Control', 'no-store, private');
    });

    it('trả 422 khi limit vượt ngưỡng', function (): void {
        startSession(['limit' => 100000])
            ->assertStatus(422)->assertJsonValidationErrors('limit');
    });

    it('trả 422 với mode lạ', function (): void {
        startSession(['mode' => 'flashcard'])
            ->assertStatus(422)->assertJsonValidationErrors('mode');
    });

    it('trả 422 với nguồn lạ', function (): void {
        startSession(['source' => 'random'])
            ->assertStatus(422)->assertJsonValidationErrors('source');
    });

    it('trả 401 khi không đăng nhập', function (): void {
        $this->postJson('/api/reviews/sessions', ['mode' => AnswerGrader::MODE_MCQ])
            ->assertUnauthorized();
    });
});

describe('payload trắc nghiệm', function (): void {
    it('có đúng 4 lựa chọn, mỗi lựa chọn mang word_id', function (): void {
        $items = startSession(['mode' => AnswerGrader::MODE_MCQ])->json('data.items');

        expect($items[0]['options'])->toHaveCount(4);

        foreach ($items[0]['options'] as $option) {
            expect($option)->toHaveKeys(['word_id', 'text'])
                ->and($option['text'])->toBeString()->not->toBeEmpty();
        }
    });

    it('KHÔNG đánh dấu lựa chọn nào là đáp án đúng', function (): void {
        // Nếu payload lộ đáp án thì bài kiểm tra vô nghĩa.
        $items = startSession(['mode' => AnswerGrader::MODE_MCQ])->json('data.items');

        foreach ($items[0]['options'] as $option) {
            expect(array_keys($option))->toBe(['word_id', 'text']);
        }
    });

    it('có đủ 3 distractor khác đáp án đúng', function (): void {
        $items = startSession(['mode' => AnswerGrader::MODE_MCQ])->json('data.items');

        $texts = collect($items[0]['options'])->pluck('text');

        expect($texts->unique())->toHaveCount(4)
            ->and($texts)->toContain('học tập');
    });

    it('không kèm chữ Hán trong mode gõ', function (): void {
        // Chữ Hán chính là câu trả lời.
        $items = startSession(['mode' => AnswerGrader::MODE_TYPING])->json('data.items');

        expect($items[0])->toHaveKeys(['user_word_id', 'prompt_han_viet', 'hint'])
            ->and($items[0]['prompt_han_viet'])->toBe('học tập')
            ->and($items[0])->not->toHaveKey('word');
    });

    it('đặt planned_count bằng số thẻ thực sự phát ra', function (): void {
        $response = startSession(['limit' => 50]);

        expect($response->json('data.session.planned_count'))
            ->toBe(count($response->json('data.items')));
    });

    it('trả bộ đếm là SỐ 0, không phải null', function (): void {
        /*
         * `create()` không đọc lại default của cột từ DB, nên bỏ qua hai trường
         * này sẽ cho model vừa tạo mang `null`. App khai kiểu `number` và dùng
         * thẳng cho thanh tiến độ — kết quả là " / 10" cùng `aria-valuenow`
         * rỗng, cho tới lượt trả lời đầu tiên.
         */
        startSession()->assertCreated()
            ->assertJsonPath('data.session.answered_count', 0)
            ->assertJsonPath('data.session.correct_count', 0)
            ->assertJsonPath('data.session.score', null)
            ->assertJsonPath('data.session.grade', null);
    });
});

describe('chấm bài trắc nghiệm', function (): void {
    it('chấm đúng bằng answer_word_id, không cần state phiên', function (): void {
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => openSessionId(['mode' => AnswerGrader::MODE_MCQ]),
            'mode' => AnswerGrader::MODE_MCQ,
            'answer_word_id' => $this->word->id,
        ])->assertOk()->assertJsonPath('data.correct', true);
    });

    it('chấm sai khi chọn word_id khác', function (): void {
        $wrong = DictionaryWord::where('id', '!=', $this->word->id)->value('id');

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => openSessionId(['mode' => AnswerGrader::MODE_MCQ]),
            'mode' => AnswerGrader::MODE_MCQ,
            'answer_word_id' => $wrong,
        ])->assertOk()->assertJsonPath('data.correct', false);
    });
});

describe('chấm bài mode gõ', function (): void {
    it('chấp nhận chữ Hán, phồn thể và pinyin', function (string $answer): void {
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => openSessionId(),
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
            'review_session_id' => openSessionId(),
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '银行',
        ])->assertOk()->assertJsonPath('data.correct', false);
    });

    it('từ chối câu trả lời dài quá 64 ký tự', function (): void {
        // Không giới hạn thì một chuỗi 1MB lặp lại đủ để lấp đĩa VPS.
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => openSessionId(),
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => str_repeat('a', 65),
        ])->assertStatus(422)->assertJsonValidationErrors('answer');
    });

    it('bắt buộc có review_session_id', function (): void {
        // Nullable ở DB là để chứa log CŨ, không phải để cho phép lượt nộp mới
        // thiếu phiên.
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertStatus(422)->assertJsonValidationErrors('review_session_id');
    });
});

describe('IDOR — red team H1', function (): void {
    it('user B nộp bài cho user_word của A: 404, không ghi log, không đổi lịch', function (): void {
        $before = $this->userWord->fresh();
        $theirSession = ReviewSession::factory()->open()->create(['user_id' => $this->other->id]);

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $theirSession->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ], as: $this->other)->assertNotFound();

        $after = $this->userWord->fresh();

        expect(ReviewLog::count())->toBe(0)
            ->and($after->next_review_at)->toEqual($before->next_review_at)
            ->and($after->review_count)->toBe($before->review_count)
            ->and($after->interval_days)->toBe($before->interval_days);
    });

    it('nộp bài lên phiên của người khác: 404', function (): void {
        // Cùng quy ước không tiết lộ như `user_word_id`: phiên có thật nhưng
        // không phải của họ thì hành xử như không tồn tại.
        $theirSession = ReviewSession::factory()->open()->create(['user_id' => $this->other->id]);

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $theirSession->id,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertNotFound();

        expect(ReviewLog::count())->toBe(0);
    });

    it('trả 404 cho user_word_id không tồn tại', function (): void {
        submitAnswer([
            'user_word_id' => 999999,
            'review_session_id' => openSessionId(),
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertNotFound();
    });

    it('trả 409 khi phiên đã chốt', function (): void {
        /*
         * 409 chứ không 404: phiên có thật và thuộc về họ. Nói dối ở đây khiến
         * app không phân biệt được "phiên hết hạn" với "lỗi", và người dùng mất
         * câu trả lời mà không hiểu vì sao.
         */
        $sessionId = openSessionId();
        finishSession($sessionId)->assertOk();

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertStatus(409);
    });
});

describe('lịch ôn sau khi nộp', function (): void {
    it('trả lời đúng đặt lịch 1 ngày cho lần đầu', function (): void {
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => openSessionId(),
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
            'review_session_id' => openSessionId(),
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => 'sai',
        ])->assertOk();

        $fresh = $this->userWord->fresh();

        expect($fresh->interval_days)->toBe(0)
            ->and($fresh->repetitions)->toBe(0)
            ->and($fresh->correct_count)->toBe(0);
    });

    it('ghi log mỗi lượt, gắn về phiên', function (): void {
        $sessionId = openSessionId();

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertOk();

        $log = ReviewLog::sole();

        expect($log->is_correct)->toBeTrue()
            ->and($log->is_retry)->toBeFalse()
            ->and($log->review_session_id)->toBe($sessionId)
            ->and($log->answer_raw)->toBe('学习')
            ->and($log->interval_before)->toBe(0)
            ->and($log->interval_after)->toBe(1);
    });
});

describe('làm lại trong phiên — red team H3', function (): void {
    it('lượt retry được ghi log nhưng KHÔNG đổi lịch', function (): void {
        $sessionId = openSessionId();

        // Sai trước.
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => 'sai',
        ])->assertOk();

        $afterWrong = $this->userWord->fresh();

        // Sửa lại đúng trong CÙNG phiên — server tự nhận ra đây là lượt làm lại.
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertOk()
            ->assertJsonPath('data.correct', true)
            ->assertJsonPath('data.is_retry', true);

        $afterRetry = $this->userWord->fresh();

        // Hình phạt SRS phải còn nguyên: nếu retry chạy scheduler thì sai-rồi-
        // sửa sẽ cho ra cùng lịch như đúng ngay từ đầu.
        expect($afterRetry->interval_days)->toBe($afterWrong->interval_days)
            ->and($afterRetry->repetitions)->toBe($afterWrong->repetitions)
            ->and($afterRetry->ease_factor)->toBe($afterWrong->ease_factor)
            ->and($afterRetry->review_count)->toBe($afterWrong->review_count);
    });

    it('KHÔNG cho client tự khai là lượt làm lại', function (): void {
        /*
         * Cờ này chi phối cả hình phạt SRS lẫn mẫu số của điểm. Nếu client khai
         * được, gửi `is_retry: true` cho mọi câu sai là ra 100 điểm mà không bị
         * phạt lịch lần nào.
         */
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => openSessionId(),
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
            'is_retry' => true,
        ])->assertOk()->assertJsonPath('data.is_retry', false);

        expect(ReviewLog::sole()->is_retry)->toBeFalse();
    });

    it('lượt đầu ở phiên KHÁC không bị coi là làm lại', function (): void {
        // Ôn lại một từ ở phiên hôm sau là lượt đầu của phiên đó.
        $first = openSessionId();

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $first,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => 'sai',
        ])->assertOk();

        finishSession($first)->assertOk();

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => openSessionId(),
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertOk()->assertJsonPath('data.is_retry', false);
    });

    it('sai rồi sửa KHÔNG cho ra lịch giống đúng ngay lần đầu', function (): void {
        // Success Criteria của P14, viết thẳng thành test.
        $wrongThenRight = UserWord::create([
            'user_id' => $this->user->id,
            'word_id' => DictionaryWord::where('simplified', '银行')->value('id'),
        ]);

        $sessionId = openSessionId();

        submitAnswer([
            'user_word_id' => $wrongThenRight->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => 'sai',
        ]);
        submitAnswer([
            'user_word_id' => $wrongThenRight->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '银行',
        ]);

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ]);

        expect($wrongThenRight->fresh()->interval_days)
            ->not->toBe($this->userWord->fresh()->interval_days);
    });
});

describe('phiên ôn từ hay sai', function (): void {
    beforeEach(function (): void {
        // Chưa tới hạn, nhưng đã sai 3 lần.
        $this->userWord->update([
            'review_count' => 5,
            'correct_count' => 2,
            'interval_days' => 10,
            'repetitions' => 3,
            'next_review_at' => now()->addDays(10),
        ]);
    });

    it('lấy từ hay sai bất kể lịch tới hạn', function (): void {
        $items = startSession(['source' => ReviewSession::SOURCE_WEAK])
            ->assertCreated()->json('data.items');

        expect($items)->toHaveCount(1)
            ->and($items[0]['user_word_id'])->toBe($this->userWord->id);
    });

    it('trả lời ĐÚNG: tăng bộ đếm nhưng KHÔNG kéo dài lịch', function (): void {
        /*
         * Bất biến quan trọng nhất của chế độ này, và nó là HAI khẳng định chứ
         * không một:
         *
         * - lịch không đổi, nếu không thì cày lại vài lần là từ nhảy lên
         *   `mastered` dù người dùng chưa nhớ nó;
         * - bộ đếm VẪN tăng, nếu không thì "số lần sai" (suy ra bằng
         *   `review_count - correct_count`) đóng băng và từ đã thuộc lòng ở lại
         *   danh sách hay sai vĩnh viễn.
         */
        $before = $this->userWord->fresh();

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => openSessionId(['source' => ReviewSession::SOURCE_WEAK]),
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertOk()->assertJsonPath('data.correct', true);

        $after = $this->userWord->fresh();

        expect($after->next_review_at)->toEqual($before->next_review_at)
            ->and($after->interval_days)->toBe($before->interval_days)
            ->and($after->repetitions)->toBe($before->repetitions)
            ->and($after->review_count)->toBe($before->review_count + 1)
            ->and($after->correct_count)->toBe($before->correct_count + 1);
    });

    it('trả lời SAI: chạy scheduler như phiên thường', function (): void {
        // Quên thật thì là quên thật, bất kể phiên nào.
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => openSessionId(['source' => ReviewSession::SOURCE_WEAK]),
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => 'sai',
        ])->assertOk();

        $after = $this->userWord->fresh();

        expect($after->interval_days)->toBe(0)
            ->and($after->repetitions)->toBe(0);
    });

    it('ôn đúng nhiều lần vẫn làm bộ đếm nhúc nhích', function (): void {
        // Hồi quy cho vòng lặp không lối ra: nếu bộ đếm bị đóng băng trong phiên
        // weak, từ này ở lại danh sách "hay sai" mãi mãi.
        for ($i = 0; $i < 3; $i++) {
            $sessionId = openSessionId(['source' => ReviewSession::SOURCE_WEAK]);

            submitAnswer([
                'user_word_id' => $this->userWord->id,
                'review_session_id' => $sessionId,
                'mode' => AnswerGrader::MODE_TYPING,
                'answer' => '学习',
            ])->assertOk();

            finishSession($sessionId);
        }

        $fresh = $this->userWord->fresh();

        expect($fresh->review_count)->toBe(8)
            ->and($fresh->correct_count)->toBe(5);
    });
});

describe('chốt phiên', function (): void {
    it('tính điểm và xếp loại', function (): void {
        $sessionId = openSessionId();

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertOk();

        finishSession($sessionId)->assertOk()
            ->assertJsonPath('data.session.score', 100)
            ->assertJsonPath('data.session.grade', 'excellent')
            ->assertJsonPath('data.session.answered_count', 1);
    });

    it('idempotent: gọi lần hai trả cùng kết quả', function (): void {
        $sessionId = openSessionId();

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => 'sai',
        ])->assertOk();

        $first = finishSession($sessionId)->json('data.session');
        $second = finishSession($sessionId)->json('data.session');

        expect($second)->toBe($first);
    });

    it('trả danh sách lượt trả lời, gồm cả lượt làm lại', function (): void {
        $sessionId = openSessionId();

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => 'sai',
        ]);
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ]);

        $answers = finishSession($sessionId)->json('data.answers');

        expect($answers)->toHaveCount(2)
            ->and($answers[0]['is_retry'])->toBeFalse()
            ->and($answers[1]['is_retry'])->toBeTrue()
            ->and($answers[0]['word']['simplified'])->toBe('学习');
    });

    it('thời lượng không bao giờ âm', function (): void {
        $sessionId = openSessionId();

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ]);

        expect(finishSession($sessionId)->json('data.session.duration_seconds'))
            ->toBeGreaterThanOrEqual(0);
    });

    it('chốt phiên của người khác: 404', function (): void {
        $theirs = ReviewSession::factory()->open()->create(['user_id' => $this->other->id]);

        finishSession($theirs->id)->assertNotFound();
    });

    it('đặt Cache-Control private, no-store', function (): void {
        finishSession(openSessionId())->assertHeader('Cache-Control', 'no-store, private');
    });
});

describe('bộ đếm phiên khớp log', function (): void {
    it('đếm đúng lượt đầu và bỏ qua lượt làm lại', function (): void {
        $sessionId = openSessionId();
        $second = UserWord::create([
            'user_id' => $this->user->id,
            'word_id' => DictionaryWord::where('simplified', '银行')->value('id'),
        ]);

        // đúng · sai · làm lại (đúng)
        submitAnswer([
            'user_word_id' => $this->userWord->id, 'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING, 'answer' => '学习',
        ]);
        submitAnswer([
            'user_word_id' => $second->id, 'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING, 'answer' => 'sai',
        ]);
        submitAnswer([
            'user_word_id' => $second->id, 'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING, 'answer' => '银行',
        ]);

        $session = ReviewSession::find($sessionId);
        $logs = ReviewLog::where('review_session_id', $sessionId);

        expect((clone $logs)->count())->toBe(3)
            ->and($session->answered_count)->toBe((clone $logs)->where('is_retry', false)->count())
            ->and($session->answered_count)->toBe(2)
            ->and($session->correct_count)->toBe(1);
    });
});

describe('log sống sót khi xóa từ khỏi kho — red team H5', function (): void {
    it('vẫn ĐỌC được lịch sử phiên sau khi xoá từ khỏi kho', function (): void {
        /*
         * `user_words` dùng soft delete chính là để log sống sót. Nhưng global
         * scope của soft delete áp cả lên quan hệ `belongsTo`, nên nếu quan hệ
         * thiếu `withTrashed()` thì nó trả `null` cho đúng những log mà cơ chế
         * kia được dựng ra để bảo vệ — và màn lịch sử nổ 500 vĩnh viễn.
         *
         * Test cũ chỉ đếm log; nó không bao giờ ĐỌC LẠI phiên sau khi xoá, nên
         * đường đọc không được kiểm ở đúng trạng thái mà repo cố tình tạo ra.
         */
        $sessionId = openSessionId();

        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => $sessionId,
            'mode' => AnswerGrader::MODE_TYPING,
            'answer' => '学习',
        ])->assertOk();

        finishSession($sessionId)->assertOk();

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/vocabulary/{$this->userWord->id}")->assertNoContent();

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/reviews/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.answers.0.word.simplified', '学习');
    });

    it('giữ nguyên review_logs sau khi soft-delete user_word', function (): void {
        submitAnswer([
            'user_word_id' => $this->userWord->id,
            'review_session_id' => openSessionId(),
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
