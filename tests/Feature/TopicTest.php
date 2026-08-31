<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserSkippedWord;
use App\Models\UserWord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->topic = Topic::factory()->create(['slug' => 'tinh-yeu', 'sort_order' => 0]);
});

/** Gắn `$count` từ vào chủ đề, trả về collection từ điển. */
function attachWords(Topic $topic, int $count): Collection
{
    $words = DictionaryWord::factory()->count($count)->create([
        'definitions_vi' => ['nghĩa sạch'],
        'han_viet_status' => DictionaryWord::STATUS_OK,
    ]);

    foreach ($words as $index => $word) {
        DB::table('topic_words')->insert([
            'topic_id' => $topic->id, 'word_id' => $word->id,
            'rank' => $index + 1, 'generated_batch' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $words;
}

describe('GET /topics', function (): void {
    it('không gọi Gemini hay Pixabay', function (): void {
        Http::fake();
        attachWords($this->topic, 3);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/topics')->assertOk();

        // Nội dung đã nằm trong bảng nhờ `topics:import`; đường request phải là
        // SQL thuần. Đây là tính chất mà cả kiến trúc D3 dựa vào.
        Http::assertNothingSent();
    });

    it('trả tiến độ và đọc tên từ TopicCatalog, không từ bảng', function (): void {
        $words = attachWords($this->topic, 5);
        UserWord::create(['user_id' => $this->user->id, 'word_id' => $words[0]->id]);

        // Bảng cố tình lệch hằng: hằng mới là nguồn sự thật.
        $this->topic->update(['name' => 'TÊN CŨ TRONG BẢNG', 'emoji' => '❓']);

        $data = $this->actingAs($this->user, 'sanctum')->getJson('/api/topics')->assertOk()->json('data.0');

        expect($data['name'])->toBe('Tình yêu & cảm xúc')
            ->and($data['emoji'])->toBe('💕')
            ->and($data['word_count'])->toBe(5)
            ->and($data['learned_count'])->toBe(1)
            ->and($data['processed_count'])->toBe(1);
    });

    it('KHÔNG đếm từ đã xoá khỏi kho', function (): void {
        // `user_words` soft-delete; một `join` thô bỏ qua scope của Eloquent,
        // nên thiếu `deleted_at is null` thì `/topics` đếm cả từ đã xoá trong
        // khi `/vocabulary/ids` thì không — hai màn nói ngược nhau.
        $words = attachWords($this->topic, 5);
        $saved = UserWord::create(['user_id' => $this->user->id, 'word_id' => $words[0]->id]);
        $saved->delete();

        $data = $this->actingAs($this->user, 'sanctum')->getJson('/api/topics')->assertOk()->json('data.0');

        expect($data['learned_count'])->toBe(0)
            ->and($data['processed_count'])->toBe(0);
    });

    it('đếm MỘT lần từ vừa lưu vừa bỏ qua, tiến độ không vượt 100%', function (): void {
        $words = attachWords($this->topic, 3);

        // Bỏ qua ở chủ đề A rồi lưu từ màn Tìm kiếm — đường đi bình thường.
        foreach ($words as $word) {
            UserWord::create(['user_id' => $this->user->id, 'word_id' => $word->id]);
            UserSkippedWord::create(['user_id' => $this->user->id, 'word_id' => $word->id]);
        }

        $data = $this->actingAs($this->user, 'sanctum')->getJson('/api/topics')->assertOk()->json('data.0');

        expect($data['processed_count'])->toBe(3)
            ->and($data['processed_count'])->toBeLessThanOrEqual($data['word_count']);
    });

    it('trả private, no-store vì mang tiến độ theo user', function (): void {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/topics')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    });

    it('không đếm tiến độ của người khác', function (): void {
        $words = attachWords($this->topic, 3);
        UserWord::create(['user_id' => User::factory()->create()->id, 'word_id' => $words[0]->id]);

        $data = $this->actingAs($this->user, 'sanctum')->getJson('/api/topics')->assertOk()->json('data.0');

        expect($data['processed_count'])->toBe(0);
    });
});

describe('GET /topics/{slug}/words', function (): void {
    it('trả cả bộ theo rank, chỉ nghĩa đầu, không trường nào theo user', function (): void {
        $words = attachWords($this->topic, 4);
        UserWord::create(['user_id' => $this->user->id, 'word_id' => $words[0]->id]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/topics/tinh-yeu/words')->assertOk();

        $data = $response->json('data');

        expect($data)->toHaveCount(4)
            ->and(array_column($data, 'rank'))->toBe([1, 2, 3, 4])
            ->and($response->json('meta.word_count'))->toBe(4)
            // Cache dài hạn + trường theo user = rò dữ liệu giữa hai tài khoản
            // trên cùng thiết bị (red team C2).
            ->and($data[0])->not->toHaveKeys(['saved', 'skipped', 'user_word_id'])
            // Một dòng nghĩa, không phải cả mảng.
            ->and($data[0]['definition_vi'])->toBeString();
    });

    it('cache được nhưng có revalidate', function (): void {
        attachWords($this->topic, 1);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/topics/tinh-yeu/words')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public, stale-while-revalidate=86400');
    });

    it('404 cho slug không tồn tại', function (): void {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/topics/khong-co/words')->assertNotFound();
    });
});

describe('bỏ qua từ', function (): void {
    it('idempotent: gọi hai lần chỉ tạo một dòng', function (): void {
        $word = DictionaryWord::factory()->create();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/topics/skips', ['word_id' => $word->id])->assertCreated();
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/topics/skips', ['word_id' => $word->id])->assertOk();

        expect(UserSkippedWord::where('user_id', $this->user->id)->count())->toBe(1);
    });

    it('trả cả từ đã BỎ QUA lẫn từ đã tự XOÁ khỏi kho', function (): void {
        // Trạng thái thứ ba: đã lưu rồi tự xoá. Xoá khỏi kho là tín hiệu từ
        // chối rõ hơn cả nút "Đã biết rồi"; không gộp vào đây thì màn học đề
        // nghị lại đúng những từ người dùng vừa xoá.
        $skipped = DictionaryWord::factory()->create();
        $removedWord = DictionaryWord::factory()->create();

        UserSkippedWord::create(['user_id' => $this->user->id, 'word_id' => $skipped->id]);
        UserWord::create(['user_id' => $this->user->id, 'word_id' => $removedWord->id])->delete();

        $ids = $this->actingAs($this->user, 'sanctum')->getJson('/api/topics/skips')->assertOk()->json('data');

        expect($ids)->toContain($skipped->id)->toContain($removedWord->id);
    });

    it('không trả skip của người khác', function (): void {
        $word = DictionaryWord::factory()->create();
        UserSkippedWord::create(['user_id' => User::factory()->create()->id, 'word_id' => $word->id]);

        expect($this->actingAs($this->user, 'sanctum')->getJson('/api/topics/skips')->json('data'))->toBeEmpty();
    });

    it('từ chối word_id không tồn tại bằng 422, không phải 500', function (): void {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/topics/skips', ['word_id' => 999999])
            ->assertStatus(422);
    });
});

it('mọi endpoint chủ đề đòi đăng nhập', function (string $method, string $uri): void {
    $this->{$method.'Json'}($uri, [])->assertUnauthorized();
})->with([
    ['get', '/api/topics'],
    ['get', '/api/topics/skips'],
    ['post', '/api/topics/skips'],
    ['get', '/api/topics/tinh-yeu/words'],
]);
