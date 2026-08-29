<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\ReviewLog;
use App\Models\ReviewSession;
use App\Models\User;
use App\Models\UserWord;
use App\Services\Review\AnswerGrader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

    DictionaryWord::factory()->count(10)->create(['is_priority' => true]);

    $this->word = DictionaryWord::where('simplified', '学习')->sole();
    $this->userWord = UserWord::create([
        'user_id' => $this->user->id,
        'word_id' => $this->word->id,
    ]);
});

function getJsonAs(string $url, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? test()->user, 'sanctum')->getJson($url);
}

/** Ghi thẳng một lượt trả lời — chỉ để dựng tình huống cho test ĐỌC. */
function seedLog(
    UserWord $userWord,
    bool $isCorrect,
    bool $isRetry = false,
    ?ReviewSession $session = null,
    ?string $answeredAt = null,
): ReviewLog {
    return ReviewLog::create([
        'user_id' => $userWord->user_id,
        'user_word_id' => $userWord->id,
        'review_session_id' => $session?->id,
        'mode' => AnswerGrader::MODE_TYPING,
        'is_correct' => $isCorrect,
        'is_retry' => $isRetry,
        'answer_raw' => 'x',
        'interval_before' => 0,
        'interval_after' => 0,
        'answered_at' => $answeredAt ?? now()->toDateTimeString(),
    ]);
}

describe('GET /reviews/sessions', function (): void {
    it('liệt kê phiên mới nhất trước', function (): void {
        $old = ReviewSession::factory()->create([
            'user_id' => $this->user->id, 'started_at' => now()->subDays(5),
            'finished_at' => now()->subDays(5)->addMinutes(5), 'answered_count' => 3,
        ]);
        $new = ReviewSession::factory()->create([
            'user_id' => $this->user->id, 'started_at' => now()->subHour(),
            'finished_at' => now(), 'answered_count' => 4,
        ]);

        $ids = collect(getJsonAs('/api/reviews/sessions')->assertOk()->json('data'))->pluck('id');

        expect($ids->all())->toBe([$new->id, $old->id]);
    });

    it('KHÔNG trả phiên đang mở', function (): void {
        // Phiên đang mở là phiên người dùng ĐANG làm, không phải lịch sử.
        ReviewSession::factory()->open()->create([
            'user_id' => $this->user->id, 'answered_count' => 2,
        ]);

        expect(getJsonAs('/api/reviews/sessions')->json('data'))->toBeEmpty();
    });

    it('KHÔNG trả phiên không có lượt trả lời nào', function (): void {
        /*
         * Lưới an toàn kép bên cạnh việc `finishStale()` đã xoá phiên rỗng.
         * Không có nó, thao tác "mở trang ôn rồi thoát" sẽ đắp đầy lịch sử bằng
         * những dòng "0 điểm · Cần ôn thêm".
         */
        ReviewSession::factory()->create([
            'user_id' => $this->user->id,
            'answered_count' => 0, 'correct_count' => 0, 'score' => 0, 'grade' => null,
        ]);

        expect(getJsonAs('/api/reviews/sessions')->json('data'))->toBeEmpty();
    });

    it('KHÔNG trả phiên của người khác', function (): void {
        ReviewSession::factory()->create(['user_id' => $this->other->id, 'answered_count' => 3]);

        expect(getJsonAs('/api/reviews/sessions')->json('data'))->toBeEmpty();
    });

    it('phân trang cursor qua 3 trang không lặp và không bỏ sót', function (): void {
        ReviewSession::factory()->count(45)->create([
            'user_id' => $this->user->id, 'answered_count' => 2,
        ]);

        $seen = [];
        $url = '/api/reviews/sessions';

        for ($page = 0; $page < 3; $page++) {
            $response = getJsonAs($url)->assertOk();
            $seen = array_merge($seen, collect($response->json('data'))->pluck('id')->all());

            $cursor = $response->json('meta.next_cursor');

            if ($cursor === null) {
                break;
            }

            $url = '/api/reviews/sessions?cursor='.$cursor;
        }

        expect($seen)->toHaveCount(45)
            ->and(array_unique($seen))->toHaveCount(45);
    });

    it('đặt Cache-Control private, no-store', function (): void {
        getJsonAs('/api/reviews/sessions')->assertHeader('Cache-Control', 'no-store, private');
    });
});

describe('GET /reviews/sessions/{id}', function (): void {
    it('trả tổng kết kèm từng lượt trả lời, gồm cả lượt làm lại', function (): void {
        $session = ReviewSession::factory()->create([
            'user_id' => $this->user->id, 'answered_count' => 1, 'correct_count' => 0,
        ]);

        seedLog($this->userWord, isCorrect: false, session: $session);
        seedLog($this->userWord, isCorrect: true, isRetry: true, session: $session);

        $data = getJsonAs("/api/reviews/sessions/{$session->id}")->assertOk()->json('data');

        expect($data['answers'])->toHaveCount(2)
            ->and($data['answers'][0]['is_retry'])->toBeFalse()
            ->and($data['answers'][1]['is_retry'])->toBeTrue()
            ->and($data['answers'][0]['word']['simplified'])->toBe('学习')
            ->and($data['session']['id'])->toBe($session->id);
    });

    it('trả cùng hình dạng với endpoint chốt phiên', function (): void {
        /*
         * Hai payload khác nhau cho cùng một thực thể sẽ cho ra hai con số "từ
         * sai" trên hai màn của cùng một phiên.
         */
        $session = ReviewSession::factory()->open()->create(['user_id' => $this->user->id]);
        seedLog($this->userWord, isCorrect: false, session: $session);

        $finished = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/reviews/sessions/{$session->id}/finish")->json('data');

        $fetched = getJsonAs("/api/reviews/sessions/{$session->id}")->json('data');

        expect(array_keys($fetched))->toBe(array_keys($finished))
            ->and($fetched['answers'])->toBe($finished['answers']);
    });

    it('phiên của người khác: 404', function (): void {
        $theirs = ReviewSession::factory()->create(['user_id' => $this->other->id]);

        getJsonAs("/api/reviews/sessions/{$theirs->id}")->assertNotFound();
    });

    it('đặt Cache-Control private, no-store', function (): void {
        $session = ReviewSession::factory()->create(['user_id' => $this->user->id]);

        getJsonAs("/api/reviews/sessions/{$session->id}")
            ->assertHeader('Cache-Control', 'no-store, private');
    });
});

describe('GET /reviews/weak-words', function (): void {
    it('KHÔNG trả từ chưa từng sai', function (): void {
        $this->userWord->update(['review_count' => 4, 'correct_count' => 4]);

        expect(getJsonAs('/api/reviews/weak-words')->assertOk()->json('data'))->toBeEmpty();
    });

    it('sắp theo số lần sai giảm dần', function (): void {
        $ids = DictionaryWord::query()
            ->whereIn('han_viet_status', [DictionaryWord::STATUS_OK, DictionaryWord::STATUS_MANUAL])
            ->whereNotNull('han_viet')
            ->where('id', '!=', $this->word->id)
            ->limit(2)->pluck('id');

        $this->userWord->update(['review_count' => 5, 'correct_count' => 4]);       // sai 1
        $mid = UserWord::create([
            'user_id' => $this->user->id, 'word_id' => $ids[0],
            'review_count' => 6, 'correct_count' => 3,                              // sai 3
        ]);
        $worst = UserWord::create([
            'user_id' => $this->user->id, 'word_id' => $ids[1],
            'review_count' => 9, 'correct_count' => 3,                              // sai 6
        ]);

        $data = getJsonAs('/api/reviews/weak-words')->json('data');

        expect(collect($data)->pluck('user_word_id')->all())
            ->toBe([$worst->id, $mid->id, $this->userWord->id])
            ->and($data[0]['wrong_count'])->toBe(6)
            ->and($data[0]['accuracy'])->toBe(33);
    });

    it('phân trang không mất dữ liệu KỂ CẢ khi số lần sai đổi giữa hai trang', function (): void {
        /*
         * Đây là test bắt lỗi `cursorPaginate` + `orderByRaw`: cursor lọc bỏ mọi
         * order không có `direction`, nên con trỏ rút về `id` một mình và trang
         * sau mất sạch những từ có id lớn hơn dòng cuối trang trước — im lặng,
         * không exception.
         */
        // 30 từ để chắc chắn có HAI trang thật (mỗi trang 20 mục).
        $words = DictionaryWord::factory()->count(30)->create();

        foreach ($words as $index => $word) {
            UserWord::create([
                'user_id' => $this->user->id,
                'word_id' => $word->id,
                'review_count' => 10,
                // Số lần sai 1..5, lặp lại — đủ để nhiều mục cùng hạng và bắt
                // được lỗi tie-break.
                'correct_count' => 10 - (($index % 5) + 1),
            ]);
        }

        $total = UserWord::query()->where('user_id', $this->user->id)
            ->whereRaw('review_count - correct_count >= 1')->count();

        $first = getJsonAs('/api/reviews/weak-words')->assertOk();
        $seen = collect($first->json('data'))->pluck('user_word_id')->all();

        expect($seen)->toHaveCount(20);

        // Một lượt trả lời xen giữa hai trang làm ĐỔI khoá sắp xếp — đúng tình
        // huống mà cursor pagination trên khoá dẫn xuất sẽ mất dữ liệu.
        UserWord::find($seen[0])->increment('correct_count');

        $second = getJsonAs('/api/reviews/weak-words?page=2')->assertOk();
        $seen = array_merge($seen, collect($second->json('data'))->pluck('user_word_id')->all());

        expect(count($seen))->toBe($total)
            ->and(array_unique($seen))->toHaveCount($total);
    });

    it('KHÔNG trả từ của người khác', function (): void {
        $theirWord = UserWord::create([
            'user_id' => $this->other->id,
            'word_id' => DictionaryWord::where('simplified', '银行')->value('id'),
            'review_count' => 9, 'correct_count' => 1,
        ]);

        $ids = collect(getJsonAs('/api/reviews/weak-words')->json('data'))->pluck('user_word_id');

        expect($ids)->not->toContain($theirWord->id);
    });

    it('đặt Cache-Control private, no-store', function (): void {
        getJsonAs('/api/reviews/weak-words')->assertHeader('Cache-Control', 'no-store, private');
    });
});

describe('last_wrong_at', function (): void {
    it('LOẠI lượt làm lại, khớp với cách wrong_count được đếm', function (): void {
        /*
         * `wrong_count` cạnh nó chỉ đếm lượt đầu. Tính `last_wrong_at` trên cả
         * lượt làm lại sẽ đặt hai con số tính trên hai tập log khác nhau lên
         * cùng một dòng UI.
         */
        $this->userWord->update(['review_count' => 3, 'correct_count' => 2]);

        seedLog($this->userWord, isCorrect: false, answeredAt: now()->subDays(3)->toDateTimeString());
        seedLog($this->userWord, isCorrect: false, isRetry: true, answeredAt: now()->toDateTimeString());

        $data = getJsonAs('/api/reviews/weak-words')->json('data');

        expect($data[0]['last_wrong_at'])->toStartWith(now()->subDays(3)->format('Y-m-d'));
    });

    it('là null khi mọi lượt sai đều là làm lại', function (): void {
        $this->userWord->update(['review_count' => 2, 'correct_count' => 1]);

        seedLog($this->userWord, isCorrect: false, isRetry: true);

        expect(getJsonAs('/api/reviews/weak-words')->json('data.0.last_wrong_at'))->toBeNull();
    });

    it('giống nhau giữa weak-words và words/{id}/history', function (): void {
        // Hai endpoint không được định nghĩa "sai gần nhất" khác nhau.
        $this->userWord->update(['review_count' => 3, 'correct_count' => 1]);
        seedLog($this->userWord, isCorrect: false, answeredAt: now()->subDay()->toDateTimeString());

        $fromList = getJsonAs('/api/reviews/weak-words')->json('data.0.last_wrong_at');
        $fromDetail = getJsonAs("/api/reviews/words/{$this->word->id}/history")->json('data.last_wrong_at');

        expect($fromDetail)->toBe($fromList)->not->toBeNull();
    });

    it('chỉ tốn MỘT truy vấn cho cả trang', function (): void {
        // Mỗi từ một truy vấn là N+1 kinh điển; với 20 mục mỗi trang thì nó
        // biến một màn hình thành 21 lượt đi DB.
        $words = DictionaryWord::query()
            ->whereIn('han_viet_status', [DictionaryWord::STATUS_OK, DictionaryWord::STATUS_MANUAL])
            ->whereNotNull('han_viet')->limit(8)->pluck('id');

        foreach ($words as $wordId) {
            $userWord = UserWord::query()->updateOrCreate(
                ['user_id' => $this->user->id, 'word_id' => $wordId],
                ['review_count' => 5, 'correct_count' => 2],
            );
            seedLog($userWord, isCorrect: false);
        }

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'max("answered_at")') || str_contains($query->sql, 'MAX(answered_at)')) {
                $queries++;
            }
        });

        getJsonAs('/api/reviews/weak-words')->assertOk();

        expect($queries)->toBe(1);
    });
});

describe('GET /reviews/words/{word}/history', function (): void {
    it('trả bộ đếm, tỉ lệ đúng và các lượt gần nhất', function (): void {
        $this->userWord->update(['review_count' => 8, 'correct_count' => 3]);
        seedLog($this->userWord, isCorrect: false);

        $data = getJsonAs("/api/reviews/words/{$this->word->id}/history")->assertOk()->json('data');

        expect($data['review_count'])->toBe(8)
            ->and($data['correct_count'])->toBe(3)
            ->and($data['wrong_count'])->toBe(5)
            ->and($data['accuracy'])->toBe(38)
            ->and($data['recent'])->toHaveCount(1);
    });

    it('trả tối đa 10 lượt, mới nhất trước', function (): void {
        for ($i = 0; $i < 14; $i++) {
            seedLog($this->userWord, isCorrect: true, answeredAt: now()->subMinutes($i)->toDateTimeString());
        }

        $recent = getJsonAs("/api/reviews/words/{$this->word->id}/history")->json('data.recent');

        expect($recent)->toHaveCount(10)
            ->and($recent[0]['answered_at'])->toBeGreaterThan($recent[9]['answered_at']);
    });

    it('trả 404 khi user chưa lưu từ này', function (): void {
        $unsaved = DictionaryWord::where('simplified', '银行')->value('id');

        getJsonAs("/api/reviews/words/{$unsaved}/history")->assertNotFound();
    });

    it('giữ nguyên lịch sử sau khi xoá rồi lưu lại từ', function (): void {
        /*
         * `VocabularyController::store()` khôi phục bản ghi đã soft-delete thay
         * vì tạo hàng mới, nên `user_words.id` không đổi và log cũ vẫn trỏ đúng.
         */
        $this->userWord->update(['review_count' => 3, 'correct_count' => 1]);
        seedLog($this->userWord, isCorrect: false);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/vocabulary/{$this->userWord->id}")->assertNoContent();
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/vocabulary', ['word_id' => $this->word->id])->assertOk();

        expect(getJsonAs("/api/reviews/words/{$this->word->id}/history")->json('data.review_count'))
            ->toBe(3);
    });

    it('đặt Cache-Control private, no-store', function (): void {
        getJsonAs("/api/reviews/words/{$this->word->id}/history")
            ->assertHeader('Cache-Control', 'no-store, private');
    });
});
