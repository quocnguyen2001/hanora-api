<?php

declare(strict_types=1);

use App\Jobs\GenerateWordEnrichment;
use App\Models\DictionaryWord;
use App\Models\DictionaryWordEnrichment;
use App\Models\User;
use App\Services\Dictionary\Enrichment\EnrichmentPrompt;
use App\Services\Dictionary\Enrichment\EnrichmentValidator;
use App\Services\Gemini\GeminiClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);

    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-3.1-flash-lite']);

    $this->user = User::factory()->create();
    $this->word = DictionaryWord::where('simplified', '学习')->firstOrFail();
});

function enrichmentApi(?int $wordId = null): TestResponse
{
    $id = $wordId ?? test()->word->id;

    return test()->actingAs(test()->user, 'sanctum')
        ->getJson("/api/dictionary/words/{$id}/enrichment");
}

function fakeGemini(): void
{
    Http::fake(['*' => Http::response([
        'usage' => ['total_input_tokens' => 400, 'total_output_tokens' => 600],
        'steps' => [
            ['type' => 'thought', 'signature' => 'x'],
            ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode([
                'senses' => [['pos' => 'động từ', 'vi' => 'học, học tập']],
                'examples' => [['zh' => '我喜欢学习。', 'pinyin' => 'wǒ xǐhuān xuéxí', 'vi' => 'Tôi thích học.']],
                'characters' => [['char' => '学', 'meaning_vi' => 'học']],
                'related_words' => [['simplified' => '学生', 'pinyin' => 'xuéshēng', 'vi' => 'học sinh']],
                'idioms' => [],
                'usage_note' => 'Ghi chú.',
            ])]]],
        ],
    ])]);
}

it('yêu cầu đăng nhập', function (): void {
    $this->getJson("/api/dictionary/words/{$this->word->id}/enrichment")->assertUnauthorized();
});

describe('lần gọi đầu', function (): void {
    it('trả 202, tạo bản ghi pending và xếp job', function (): void {
        Queue::fake();

        $response = enrichmentApi();

        $response->assertStatus(202)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.status', 'pending')
            ->assertHeader('Retry-After', '3')
            ->assertHeader('Cache-Control', 'no-store, private');

        Queue::assertPushed(GenerateWordEnrichment::class, 1);
        expect(DictionaryWordEnrichment::where('word_id', $this->word->id)->exists())->toBeTrue();
    });

    it('vẫn 202 khi hỏi lại lúc đang pending', function (): void {
        Queue::fake();

        enrichmentApi()->assertStatus(202);
        enrichmentApi()->assertStatus(202);

        expect(DictionaryWordEnrichment::where('word_id', $this->word->id)->count())->toBe(1);
    });
});

describe('khi đã có nội dung', function (): void {
    beforeEach(function (): void {
        fakeGemini();
        (new GenerateWordEnrichment($this->word->id))->handle(
            app(GeminiClient::class),
            app(EnrichmentPrompt::class),
            app(EnrichmentValidator::class),
        );
    });

    it('trả 200 với payload đầy đủ và nhãn nguồn', function (): void {
        $response = enrichmentApi()->assertOk();

        expect($response->json('data.senses.0.vi'))->toBe('học, học tập')
            ->and($response->json('data.examples.0.zh'))->toBe('我喜欢学习。')
            // `meaning_vi`, KHÔNG phải `radical`: prompt v2 đã gỡ bộ thủ và số
            // nét khỏi lớp AI — chúng đến từ `dictionary_characters`.
            ->and($response->json('data.characters.0.meaning_vi'))->toBe('học')
            ->and($response->json('data.related_words.0.simplified'))->toBe('学生')
            // Người học phải biết dòng nào do máy sinh.
            ->and($response->json('data.source'))->toBe('ai')
            ->and($response->json('data.model'))->toBe('gemini-3.1-flash-lite');
    });

    it('cache dài', function (): void {
        enrichmentApi()->assertHeader('Cache-Control', 'max-age=86400, public');
    });

    it('KHÔNG gọi Gemini lần thứ hai', function (): void {
        // Tiêu chí trung tâm của cả plan: sinh một lần, dùng mãi.
        Http::fake();
        enrichmentApi()->assertOk();
        Http::assertNothingSent();
    });

    it('chạy lại job cũng không gọi Gemini nữa', function (): void {
        // Chốt chặn thứ hai, độc lập với cache HTTP: job xếp lại do retry hoặc
        // do `dictionary:enrich` chạy lần nữa đều phải dừng ở đây.
        Http::fake();

        (new GenerateWordEnrichment($this->word->id))->handle(
            app(GeminiClient::class),
            app(EnrichmentPrompt::class),
            app(EnrichmentValidator::class),
        );

        Http::assertNothingSent();
    });

    it('không rò trường theo user', function (): void {
        $keys = array_keys(enrichmentApi()->json('data'));

        expect($keys)->not->toContain('saved')->not->toContain('user_word_id');
    });
});

describe('Gemini hỏng thì suy giảm êm, không bao giờ 5xx', function (): void {
    it('ghi failed và tăng attempts khi API lỗi', function (): void {
        Http::fake(['*' => Http::response('boom', 500)]);

        (new GenerateWordEnrichment($this->word->id))->handle(
            app(GeminiClient::class),
            app(EnrichmentPrompt::class),
            app(EnrichmentValidator::class),
        );

        $row = DictionaryWordEnrichment::where('word_id', $this->word->id)->firstOrFail();

        expect($row->status)->toBe('failed')
            ->and($row->failed_reason)->toBe('http_500')
            ->and($row->attempts)->toBe(1);
    });

    it('trả 200 data:null khi cạn lượt thử, KHÔNG phải 5xx', function (): void {
        DictionaryWordEnrichment::query()->create([
            'word_id' => $this->word->id,
            'status' => DictionaryWordEnrichment::STATUS_FAILED,
            'failed_reason' => 'api_error',
        ])->forceFill(['attempts' => DictionaryWordEnrichment::MAX_ATTEMPTS])->save();

        Queue::fake();

        enrichmentApi()->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.status', 'unavailable')
            ->assertHeader('Cache-Control', 'no-store, private');

        Queue::assertNothingPushed();
    });

    it('không xếp job khi lớp AI tắt', function (): void {
        // Tắt là trạng thái cấu hình đã biết. Xếp job để nó hỏng 3 lần rồi mới
        // chịu im là đốt hàng đợi cho một câu trả lời đã biết trước.
        config(['services.gemini.key' => '']);
        Queue::fake();

        enrichmentApi()->assertOk()->assertJsonPath('meta.status', 'unavailable');
        Queue::assertNothingPushed();
    });

    it('ghi failed khi payload không có nghĩa nào dùng được', function (): void {
        Http::fake(['*' => Http::response([
            'steps' => [['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => json_encode(['senses' => [], 'examples' => []])],
            ]]],
        ])]);

        (new GenerateWordEnrichment($this->word->id))->handle(
            app(GeminiClient::class),
            app(EnrichmentPrompt::class),
            app(EnrichmentValidator::class),
        );

        $row = DictionaryWordEnrichment::where('word_id', $this->word->id)->firstOrFail();

        expect($row->status)->toBe('failed')->and($row->failed_reason)->toBe('empty_senses');
    });

    it('dừng hẳn sau MAX_ATTEMPTS lần hỏng', function (): void {
        Http::fake(['*' => Http::response('boom', 500)]);
        $args = [
            app(GeminiClient::class),
            app(EnrichmentPrompt::class),
            app(EnrichmentValidator::class),
        ];

        foreach (range(1, DictionaryWordEnrichment::MAX_ATTEMPTS + 2) as $ignored) {
            (new GenerateWordEnrichment($this->word->id))->handle(...$args);
        }

        $row = DictionaryWordEnrichment::where('word_id', $this->word->id)->firstOrFail();

        expect($row->attempts)->toBe(DictionaryWordEnrichment::MAX_ATTEMPTS);
        Http::assertSentCount(DictionaryWordEnrichment::MAX_ATTEMPTS);
    });
});

it('bỏ chữ Hán bịa trước khi cache', function (): void {
    Http::fake(['*' => Http::response([
        'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode([
            'senses' => [['pos' => 'động từ', 'vi' => 'học']],
            'examples' => [],
            'characters' => [],
            'related_words' => [
                ['simplified' => '这个词不存在', 'pinyin' => 'x', 'vi' => 'bịa'],
                ['simplified' => '学生', 'pinyin' => 'xuéshēng', 'vi' => 'học sinh'],
            ],
        ])]]]],
    ])]);

    (new GenerateWordEnrichment($this->word->id))->handle(
        app(GeminiClient::class),
        app(EnrichmentPrompt::class),
        app(EnrichmentValidator::class),
    );

    $related = enrichmentApi()->assertOk()->json('data.related_words');

    expect($related)->toHaveCount(1)->and($related[0]['simplified'])->toBe('学生');
});
