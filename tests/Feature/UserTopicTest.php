<?php

declare(strict_types=1);

use App\Jobs\GenerateUserTopic;
use App\Models\DictionaryWord;
use App\Models\Topic;
use App\Models\User;
use App\Services\Topic\TopicGenerator;
use App\Services\Topic\TopicWordResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
});

/** Chủ đề tự tạo, đã sinh xong. */
function customTopic(User $owner, string $slug = 'phim-anh'): Topic
{
    return Topic::factory()->create([
        'user_id' => $owner->id, 'slug' => $slug, 'name' => 'Phim ảnh',
        'prompt_term' => 'phim ảnh', 'status' => Topic::STATUS_READY, 'sort_order' => 100,
    ]);
}

function attachWord(Topic $topic): DictionaryWord
{
    $word = DictionaryWord::factory()->create([
        'definitions_vi' => ['nghĩa sạch'], 'han_viet_status' => DictionaryWord::STATUS_OK,
    ]);

    DB::table('topic_words')->insert([
        'topic_id' => $topic->id, 'word_id' => $word->id,
        'rank' => 1, 'generated_batch' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $word;
}

/*
 * Phạm vi cá nhân là thứ THAY THẾ bước rà bằng mắt của D3/D7. Nếu nó rò, tính
 * năng này dạy nội dung chưa ai duyệt cho người lạ — nên nhóm test dưới đây là
 * lý do tồn tại của cả file.
 */
describe('phạm vi riêng tư', function (): void {
    it('người khác KHÔNG thấy chủ đề tự tạo trong lưới', function (): void {
        customTopic($this->other);

        $slugs = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/topics')->json('data'))
            ->pluck('slug');

        expect($slugs)->not->toContain('phim-anh');
    });

    it('người khác KHÔNG đọc được bộ từ, kể cả khi đoán đúng slug', function (): void {
        // Slug suy thẳng từ tên nên đoán được — scope là hàng rào duy nhất.
        $topic = customTopic($this->other);
        attachWord($topic);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/topics/phim-anh/words')
            ->assertNotFound();
    });

    it('người tạo thì thấy và đọc được', function (): void {
        $topic = customTopic($this->user);
        attachWord($topic);

        $slugs = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/topics')->json('data'))
            ->pluck('slug');

        expect($slugs)->toContain('phim-anh');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/topics/phim-anh/words')->assertOk()
            // Bộ từ riêng KHÔNG được nằm trong cache dùng chung.
            ->assertHeader('Cache-Control', 'no-store, private');
    });

    it('chủ đề gốc vẫn toàn cục', function (): void {
        $global = Topic::factory()->create(['user_id' => null, 'slug' => 'tinh-yeu', 'sort_order' => 0]);
        attachWord($global);

        foreach ([$this->user, $this->other] as $viewer) {
            $slugs = collect($this->actingAs($viewer, 'sanctum')->getJson('/api/topics')->json('data'))
                ->pluck('slug');

            expect($slugs)->toContain('tinh-yeu');
        }
    });
});

describe('tạo chủ đề', function (): void {
    it('trả 202 và xếp job mà KHÔNG gọi Gemini trong request', function (): void {
        Bus::fake();
        Http::fake();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/topics', ['name' => 'Phim ảnh'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', Topic::STATUS_GENERATING)
            ->assertJsonPath('data.is_custom', true);

        // Sinh một bộ từ mất 16-25 giây — giữ worker PHP-FPM suốt thời gian đó
        // là lý do endpoint chỉ dispatch.
        Http::assertNothingSent();

        /*
         * Khoá luôn QUEUE, không chỉ "có dispatch".
         *
         * Chạy thử thật đã dẫm đúng bẫy này: docblock của job ghi `enrichment`
         * nhưng code không gọi `onQueue()`, nên job rơi im lặng về `default` —
         * queue của mail đặt lại mật khẩu. Không có lời gọi nào lỗi, không có
         * test nào đỏ; chỉ có worker `enrichment` chạy mãi không thấy việc.
         */
        Bus::assertDispatched(
            GenerateUserTopic::class,
            fn (GenerateUserTopic $job): bool => $job->queue === 'enrichment'
        );
    });

    it('từ chối tên trùng chủ đề gốc', function (): void {
        Bus::fake();

        // Không tự thêm hậu tố: chủ đề riêng trùng slug sẽ CHE chủ đề gốc và
        // người đó âm thầm học một bộ từ khác với mọi người.
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/topics', ['name' => 'Tình yêu'])
            ->assertStatus(422);

        Bus::assertNothingDispatched();
        expect(Topic::where('user_id', $this->user->id)->count())->toBe(0);
    });

    it('từ chối khi đã chạm trần số chủ đề của tài khoản', function (): void {
        Bus::fake();

        for ($i = 0; $i < Topic::MAX_PER_USER; $i++) {
            customTopic($this->user, "chu-de-{$i}");
        }

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/topics', ['name' => 'Một chủ đề nữa'])
            ->assertStatus(422);

        // Trần theo giờ không chặn được tích luỹ 5×24 mỗi ngày; trần tài khoản
        // mới là lớp chặn nó.
        Bus::assertNothingDispatched();
    });

    it('từ chối tên trùng chủ đề mình đã tạo', function (): void {
        Bus::fake();
        customTopic($this->user);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/topics', ['name' => 'Phim ảnh'])->assertStatus(422);
    });

    it('cho phép hai người dùng cùng đặt một tên', function (): void {
        Bus::fake();
        customTopic($this->other);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/topics', ['name' => 'Phim ảnh'])->assertStatus(202);
    });

    it('từ chối tên rỗng nghĩa sau khi tạo slug', function (): void {
        Bus::fake();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/topics', ['name' => '!!!'])->assertStatus(422);
    });
});

describe('xoá chủ đề', function (): void {
    it('xoá được chủ đề của mình, kèm bộ từ', function (): void {
        $topic = customTopic($this->user);
        attachWord($topic);

        $this->actingAs($this->user, 'sanctum')->deleteJson('/api/topics/phim-anh')->assertNoContent();

        expect(Topic::where('slug', 'phim-anh')->exists())->toBeFalse()
            ->and(DB::table('topic_words')->where('topic_id', $topic->id)->count())->toBe(0);
    });

    it('chủ đề gốc trả 404, không phải 403', function (): void {
        Topic::factory()->create(['user_id' => null, 'slug' => 'tinh-yeu', 'sort_order' => 0]);

        // 403 xác nhận nó tồn tại và thuộc về ai đó.
        $this->actingAs($this->user, 'sanctum')->deleteJson('/api/topics/tinh-yeu')->assertNotFound();
        expect(Topic::where('slug', 'tinh-yeu')->exists())->toBeTrue();
    });

    it('không xoá được chủ đề của người khác', function (): void {
        customTopic($this->other);

        $this->actingAs($this->user, 'sanctum')->deleteJson('/api/topics/phim-anh')->assertNotFound();
        expect(Topic::where('slug', 'phim-anh')->exists())->toBeTrue();
    });
});

describe('job sinh từ', function (): void {
    beforeEach(function (): void {
        config(['services.gemini.key' => 'test-key']);
        Sleep::fake();
    });

    function geminiTopicResponse(array $items): array
    {
        return [
            'status' => 'completed',
            'usage' => ['total_input_tokens' => 10, 'total_output_tokens' => 20],
            'steps' => [['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)],
            ]]],
            'model' => 'test',
        ];
    }

    it('sinh xong thì chuyển sang ready và có từ', function (): void {
        $word = DictionaryWord::factory()->create([
            'simplified' => '电影', 'pinyin_numbered' => 'dian4 ying3',
            'definitions_vi' => ['phim; điện ảnh'], 'han_viet' => 'điện ảnh',
            'han_viet_status' => DictionaryWord::STATUS_OK,
        ]);

        $items = [['zh' => '电影', 'pinyin' => 'dian4 ying3', 'vi' => 'phim']];
        Http::fake(['*' => Http::sequence()
            ->push(geminiTopicResponse($items))->push(geminiTopicResponse($items))]);

        $topic = Topic::factory()->create([
            'user_id' => $this->user->id, 'slug' => 'phim-anh', 'name' => 'Phim ảnh',
            'prompt_term' => 'phim ảnh', 'status' => Topic::STATUS_GENERATING, 'sort_order' => 100,
        ]);

        (new GenerateUserTopic($topic->id))->handle(app(TopicGenerator::class), app(TopicWordResolver::class));

        expect($topic->fresh()->status)->toBe(Topic::STATUS_READY)
            ->and(DB::table('topic_words')->where('topic_id', $topic->id)->pluck('word_id')->all())
            ->toBe([$word->id]);
    });

    it('429 → failed kèm lý do, KHÔNG treo ở generating', function (): void {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '1'])]);

        $topic = Topic::factory()->create([
            'user_id' => $this->user->id, 'slug' => 'phim-anh', 'prompt_term' => 'phim ảnh',
            'status' => Topic::STATUS_GENERATING, 'sort_order' => 100,
        ]);

        (new GenerateUserTopic($topic->id))->handle(app(TopicGenerator::class), app(TopicWordResolver::class));

        // Treo ở `generating` = app poll mãi và một suất trong trần 20 bị giữ
        // bởi thứ không bao giờ xong.
        expect($topic->fresh()->status)->toBe(Topic::STATUS_FAILED)
            ->and($topic->fresh()->failed_reason)->toBe('rate_limited');
    });

    it('không từ nào dùng được → failed, không phải ready rỗng', function (): void {
        // Ca này bị chặn ở TẦNG GENERATOR, không phải ở job: `isWritable()`
        // đòi `words !== []`, nên bộ rỗng ra `generation_failed`. Nhánh
        // `no_words` trong job là lớp phòng thủ cho trường hợp generator và
        // resolver phân giải lệch nhau — hiện đo được là 0 lệch trên 863 từ.
        $items = [['zh' => '不存在của từ này', 'pinyin' => 'bu4', 'vi' => 'không có']];
        Http::fake(['*' => Http::sequence()
            ->push(geminiTopicResponse($items))->push(geminiTopicResponse($items))]);

        $topic = Topic::factory()->create([
            'user_id' => $this->user->id, 'slug' => 'phim-anh', 'prompt_term' => 'phim ảnh',
            'status' => Topic::STATUS_GENERATING, 'sort_order' => 100,
        ]);

        (new GenerateUserTopic($topic->id))->handle(app(TopicGenerator::class), app(TopicWordResolver::class));

        // `ready` với 0 từ cho ra thẻ `0/0` bấm vào là "đã học hết" ngay — người
        // dùng không hiểu chuyện gì xảy ra.
        expect($topic->fresh()->status)->toBe(Topic::STATUS_FAILED)
            ->and($topic->fresh()->failed_reason)->toBe('generation_failed');
    });

    it('chủ đề đã bị xoá trong lúc job xếp hàng → không lỗi', function (): void {
        Http::fake();

        (new GenerateUserTopic(999999))->handle(app(TopicGenerator::class), app(TopicWordResolver::class));

        // Không ném, không gọi Gemini: người dùng xoá chủ đề trong lúc job còn
        // xếp hàng là chuyện bình thường, không phải lỗi.
        Http::assertNothingSent();
    });
});

it('topics:status chỉ gate trên chủ đề gốc', function (): void {
    // Một job hỏng của một người dùng không được chặn cả lần deploy.
    customTopic($this->user)->update(['status' => Topic::STATUS_FAILED]);

    $this->artisan('topics:status')->assertFailed(); // fail vì 16 chủ đề gốc chưa nạp
    expect(Topic::whereNull('user_id')->count())->toBe(0);
});
