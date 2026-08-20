<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\User;
use App\Models\UserWord;
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
    $this->word = DictionaryWord::where('simplified', '学习')->sole();
});

function saveWord(?DictionaryWord $word = null, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? test()->user, 'sanctum')
        ->postJson('/api/vocabulary', ['word_id' => ($word ?? test()->word)->id]);
}

describe('lưu từ', function (): void {
    it('lưu được và trả 201', function (): void {
        saveWord()->assertCreated()->assertJsonPath('data.word.simplified', '学习');

        $this->assertDatabaseCount('user_words', 1);
    });

    it('lưu hai lần chỉ có một bản ghi, lần hai trả 200', function (): void {
        saveWord()->assertCreated();
        saveWord()->assertOk();

        $this->assertDatabaseCount('user_words', 1);
    });

    it('từ chối word_id không tồn tại', function (): void {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/vocabulary', ['word_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors('word_id');
    });

    it('trả 401 khi không đăng nhập', function (): void {
        $this->postJson('/api/vocabulary', ['word_id' => $this->word->id])->assertUnauthorized();
    });
});

describe('xóa và khôi phục', function (): void {
    it('xóa mềm chứ không xóa cứng', function (): void {
        $id = saveWord()->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/vocabulary/{$id}")
            ->assertNoContent();

        $this->assertSoftDeleted('user_words', ['id' => $id]);
    });

    it('khôi phục bản ghi cũ khi lưu lại, GIỮ NGUYÊN lịch sử ôn', function (): void {
        // Đây là lý do soft delete tồn tại: người dùng bỏ một từ rồi lưu lại
        // không được mất tiến độ đã học của từ đó.
        $id = saveWord()->json('data.id');

        UserWord::find($id)->update([
            'review_count' => 7,
            'correct_count' => 5,
            'status' => UserWord::STATUS_REVIEWING,
            'interval_days' => 12,
        ]);

        $this->actingAs($this->user, 'sanctum')->deleteJson("/api/vocabulary/{$id}");
        saveWord()->assertOk();

        $restored = UserWord::find($id);

        expect($restored->deleted_at)->toBeNull()
            ->and($restored->review_count)->toBe(7)
            ->and($restored->interval_days)->toBe(12)
            ->and($restored->status)->toBe(UserWord::STATUS_REVIEWING);

        $this->assertDatabaseCount('user_words', 1);
    });
});

describe('cô lập theo user', function (): void {
    it('không thấy từ của người khác', function (): void {
        saveWord(as: $this->other);

        $data = $this->actingAs($this->user, 'sanctum')->getJson('/api/vocabulary')->json('data');

        expect($data)->toBeEmpty();
    });

    it('trả 404 chứ không 403 khi xóa từ của người khác', function (): void {
        // 403 xác nhận id đó tồn tại, tức biến endpoint thành công cụ dò xem
        // người khác đã lưu bao nhiêu từ.
        $id = saveWord(as: $this->other)->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/vocabulary/{$id}")
            ->assertNotFound();

        $this->assertDatabaseHas('user_words', ['id' => $id, 'deleted_at' => null]);
    });

    it('ids chỉ trả từ của chính mình', function (): void {
        saveWord(as: $this->other);
        saveWord(as: $this->user);

        $ids = $this->actingAs($this->user, 'sanctum')->getJson('/api/vocabulary/ids')->json('data');

        expect($ids)->toBe([$this->word->id]);
    });
});

describe('endpoint ids', function (): void {
    it('đặt private, no-store để service worker không bao giờ cache', function (): void {
        // Đây là đường rò dữ liệu giữa hai tài khoản trên cùng thiết bị nếu sai.
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/vocabulary/ids')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    });

    it('không trả từ đã xóa', function (): void {
        $id = saveWord()->json('data.id');
        $this->actingAs($this->user, 'sanctum')->deleteJson("/api/vocabulary/{$id}");

        expect($this->actingAs($this->user, 'sanctum')->getJson('/api/vocabulary/ids')->json('data'))
            ->toBeEmpty();
    });

    it('không bị route /{id} nuốt mất', function (): void {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/vocabulary/ids')->assertOk();
    });
});

describe('lọc theo tab', function (): void {
    beforeEach(function (): void {
        $words = DictionaryWord::limit(4)->get();

        foreach ([
            UserWord::STATUS_NEW,
            UserWord::STATUS_LEARNING,
            UserWord::STATUS_REVIEWING,
            UserWord::STATUS_MASTERED,
        ] as $index => $status) {
            UserWord::create([
                'user_id' => $this->user->id,
                'word_id' => $words[$index]->id,
                'status' => $status,
            ]);
        }
    });

    it('tab Tất cả không lọc', function (): void {
        $data = $this->actingAs($this->user, 'sanctum')->getJson('/api/vocabulary')->json('data');

        expect($data)->toHaveCount(4);
    });

    it('tab Đang học gộp learning và reviewing', function (): void {
        // Bỏ tab này sẽ làm mọi từ đã ôn một lần biến mất khỏi bộ lọc suốt
        // tháng đầu, vì `mastered` cần `interval_days >= 30`.
        $data = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/vocabulary?status=learning')->json('data');

        expect($data)->toHaveCount(2)
            ->and(collect($data)->pluck('status')->sort()->values()->all())
            ->toBe([UserWord::STATUS_LEARNING, UserWord::STATUS_REVIEWING]);
    });

    it('tab Chưa học và Đã học lọc đúng một trạng thái', function (string $tab, string $status): void {
        $data = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/vocabulary?status={$tab}")->json('data');

        expect($data)->toHaveCount(1)->and($data[0]['status'])->toBe($status);
    })->with([
        ['new', UserWord::STATUS_NEW],
        ['mastered', UserWord::STATUS_MASTERED],
    ]);

    it('từ chối bộ lọc lạ', function (): void {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/vocabulary?status=khong-ton-tai')
            ->assertStatus(422)->assertJsonValidationErrors('status');
    });
});

describe('tìm trong kho', function (): void {
    beforeEach(function (): void {
        foreach (DictionaryWord::whereIn('simplified', ['学习', '银行', '好'])->get() as $word) {
            UserWord::create(['user_id' => $this->user->id, 'word_id' => $word->id]);
        }
    });

    it('tìm được bằng chữ Hán, pinyin, Hán-Việt và nghĩa tiếng Anh', function (string $term, string $expected): void {
        $data = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/vocabulary?q='.urlencode($term))->json('data');

        expect(collect($data)->pluck('word.simplified'))->toContain($expected);
    })->with([
        'chữ Hán' => ['学习', '学习'],
        'pinyin' => ['xuexi', '学习'],
        'Hán-Việt có dấu' => ['học tập', '学习'],
        'Hán-Việt không dấu' => ['hoc tap', '学习'],
        'nghĩa tiếng Anh' => ['bank', '银行'],
    ]);

    it('trả rỗng khi không khớp gì', function (): void {
        $data = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/vocabulary?q=zzzqqq')->json('data');

        expect($data)->toBeEmpty();
    });
});

describe('keyset pagination', function (): void {
    it('không lặp và không bỏ sót khi vừa cuộn vừa lưu thêm', function (): void {
        // Đây là lý do dùng keyset thay vì offset: chèn một mục khi đang cuộn
        // sẽ dịch biên trang của offset, khiến trang sau trả lại từ đã hiện.
        // Fixture chỉ có 14 mục, không đủ để có trang thứ hai.
        DictionaryWord::factory()->count(20)->create();
        $words = DictionaryWord::limit(25)->get();

        foreach ($words->take(21) as $word) {
            UserWord::create(['user_id' => $this->user->id, 'word_id' => $word->id]);
        }

        $first = $this->actingAs($this->user, 'sanctum')->getJson('/api/vocabulary');
        $firstIds = collect($first->json('data'))->pluck('id');
        $cursor = $first->json('meta.next_cursor');

        expect($firstIds)->toHaveCount(20)->and($cursor)->not->toBeNull();

        // Lưu thêm một từ MỚI giữa hai lần gọi — nó sẽ nằm ở đầu danh sách.
        UserWord::create(['user_id' => $this->user->id, 'word_id' => $words[22]->id]);

        $secondIds = collect(
            $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/vocabulary?cursor='.urlencode($cursor))
                ->json('data')
        )->pluck('id');

        expect($firstIds->intersect($secondIds))->toBeEmpty();
    });

    it('trả next_cursor null ở trang cuối', function (): void {
        saveWord();

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/vocabulary')
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('meta.has_more', false);
    });
});
