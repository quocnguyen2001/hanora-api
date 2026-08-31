<?php

declare(strict_types=1);

use App\Jobs\TranslateWordExamples;
use App\Models\DictionaryExample;
use App\Models\DictionaryWord;
use App\Models\User;
use App\Services\Dictionary\Examples\ExampleTranslationPrompt;
use App\Services\Gemini\GeminiClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
    $this->word = DictionaryWord::where('simplified', '学习')->sole();
});

function addTranslatableExample(DictionaryWord $word, array $overrides = []): DictionaryExample
{
    return DictionaryExample::create([
        'word_id' => $word->id,
        'sentence_zh' => '我喜欢学习中文。',
        'translation_en' => 'I like studying Chinese.',
        'contributor' => 'someuser',
        'license' => 'CC BY 2.0 FR',
        'char_length' => 8,
        'quality_score' => 100,
        'source' => 'tatoeba',
        'source_id' => (string) random_int(1, 999999),
        ...$overrides,
    ]);
}

function translationApi(?int $wordId = null): TestResponse
{
    $id = $wordId ?? test()->word->id;

    return test()->actingAs(test()->user, 'sanctum')
        ->getJson("/api/dictionary/words/{$id}/example-translations");
}

function exampleTranslationResponse(array $items): array
{
    return [
        'usage' => ['total_input_tokens' => 400, 'total_output_tokens' => 120],
        'steps' => [
            ['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)],
            ]],
        ],
    ];
}

function runTranslateJob(int $wordId): void
{
    (new TranslateWordExamples($wordId))->handle(
        app(GeminiClient::class),
        app(ExampleTranslationPrompt::class),
    );
}

it('yêu cầu đăng nhập', function (): void {
    $this->getJson("/api/dictionary/words/{$this->word->id}/example-translations")
        ->assertUnauthorized();
});

describe('endpoint', function (): void {
    it('trả mảng rỗng cho từ không có câu ví dụ', function (): void {
        Queue::fake();

        translationApi()
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.status', 'ready');

        Queue::assertNothingPushed();
    });

    it('xếp job và trả 202 khi câu chưa được dịch', function (): void {
        Queue::fake();
        addTranslatableExample($this->word);

        $response = translationApi();

        $response->assertStatus(202)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.status', 'pending')
            ->assertHeader('Retry-After', '3');

        expect($response->headers->get('Cache-Control'))->toContain('no-store');

        Queue::assertPushed(TranslateWordExamples::class);
    });

    it('trả bản dịch và cho phép cache khi đã dịch xong', function (): void {
        Queue::fake();
        $example = addTranslatableExample($this->word, [
            'translation_vi' => 'Tôi thích học tiếng Trung.',
            'vi_version' => ExampleTranslationPrompt::VERSION,
        ]);

        $response = translationApi();

        $response->assertOk()
            ->assertJsonPath('meta.status', 'ready')
            ->assertJsonPath('data.0.id', $example->id)
            ->assertJsonPath('data.0.translation_vi', 'Tôi thích học tiếng Trung.');

        expect($response->headers->get('Cache-Control'))->toContain('public');

        // Đã xong thì không được xếp thêm job nào nữa.
        Queue::assertNothingPushed();
    });

    it('KHÔNG trả bản dịch sinh bởi phiên bản prompt cũ', function (): void {
        Queue::fake();
        addTranslatableExample($this->word, [
            'translation_vi' => 'bản cũ',
            'vi_version' => ExampleTranslationPrompt::VERSION - 1,
        ]);

        translationApi()->assertStatus(202)->assertJsonPath('data', []);

        Queue::assertPushed(TranslateWordExamples::class);
    });

    it('trả unavailable mà không xếp job khi thiếu key Gemini', function (): void {
        Queue::fake();
        config(['services.gemini.key' => null]);
        addTranslatableExample($this->word);

        translationApi()
            ->assertOk()
            ->assertJsonPath('meta.status', 'unavailable');

        Queue::assertNothingPushed();
    });

    it('trả unavailable mà không xếp job khi đã cạn lượt thử', function (): void {
        Queue::fake();
        addTranslatableExample($this->word, ['vi_attempts' => DictionaryExample::MAX_ATTEMPTS]);

        translationApi()
            ->assertOk()
            ->assertJsonPath('meta.status', 'unavailable');

        Queue::assertNothingPushed();
    });

    it('giữ lại phần đã dịch được khi một câu cạn lượt', function (): void {
        // Vứt cả lô vì một câu hỏng là vứt đi bản dịch đã trả tiền để có.
        Queue::fake();
        $done = addTranslatableExample($this->word, [
            'translation_vi' => 'Tôi thích học tiếng Trung.',
            'vi_version' => ExampleTranslationPrompt::VERSION,
        ]);
        addTranslatableExample($this->word, [
            'sentence_zh' => '他在学习。',
            'vi_attempts' => DictionaryExample::MAX_ATTEMPTS,
        ]);

        translationApi()
            ->assertOk()
            ->assertJsonPath('meta.status', 'unavailable')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $done->id);
    });

    it('không trả quá số câu mà word detail hiển thị', function (): void {
        Queue::fake();

        foreach (range(1, DictionaryExample::MAX_PER_WORD + 2) as $i) {
            addTranslatableExample($this->word, [
                'sentence_zh' => "第{$i}句学习。",
                'translation_vi' => "Câu {$i}.",
                'vi_version' => ExampleTranslationPrompt::VERSION,
            ]);
        }

        translationApi()->assertOk()->assertJsonCount(DictionaryExample::MAX_PER_WORD, 'data');
    });
});

describe('job', function (): void {
    it('ghi bản dịch và phiên bản prompt', function (): void {
        $example = addTranslatableExample($this->word);

        Http::fake(['*' => Http::response(exampleTranslationResponse([
            ['i' => 0, 'vi' => 'Tôi thích học tiếng Trung.'],
        ]))]);

        runTranslateJob($this->word->id);

        expect($example->refresh()->translation_vi)->toBe('Tôi thích học tiếng Trung.')
            ->and($example->vi_version)->toBe(ExampleTranslationPrompt::VERSION)
            ->and($example->vi_attempts)->toBe(0);
    });

    it('không gọi Gemini lần thứ hai cho câu đã dịch', function (): void {
        addTranslatableExample($this->word, [
            'translation_vi' => 'Tôi thích học tiếng Trung.',
            'vi_version' => ExampleTranslationPrompt::VERSION,
        ]);

        Http::fake();

        runTranslateJob($this->word->id);

        Http::assertNothingSent();
    });

    it('không gọi Gemini khi câu đã cạn lượt thử', function (): void {
        addTranslatableExample($this->word, ['vi_attempts' => DictionaryExample::MAX_ATTEMPTS]);

        Http::fake();

        runTranslateJob($this->word->id);

        Http::assertNothingSent();
    });

    it('đếm một lần hỏng khi Gemini trả lỗi', function (): void {
        $example = addTranslatableExample($this->word);

        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        runTranslateJob($this->word->id);

        expect($example->refresh()->vi_attempts)->toBe(1)
            ->and($example->translation_vi)->toBeNull();
    });

    it('từ chối bản dịch còn sót chữ Hán và tính là một lần hỏng', function (): void {
        // Còn chữ Hán nghĩa là model chép lại câu gốc thay vì dịch.
        $example = addTranslatableExample($this->word);

        Http::fake(['*' => Http::response(exampleTranslationResponse([
            ['i' => 0, 'vi' => 'Tôi thích học 中文.'],
        ]))]);

        runTranslateJob($this->word->id);

        expect($example->refresh()->translation_vi)->toBeNull()
            ->and($example->vi_attempts)->toBe(1);
    });

    it('lưu câu model trả về và đếm hỏng riêng cho câu model bỏ sót', function (): void {
        $first = addTranslatableExample($this->word);
        $second = addTranslatableExample($this->word, ['sentence_zh' => '他在学习。']);

        Http::fake(['*' => Http::response(exampleTranslationResponse([
            ['i' => 0, 'vi' => 'Tôi thích học tiếng Trung.'],
        ]))]);

        runTranslateJob($this->word->id);

        expect($first->refresh()->translation_vi)->toBe('Tôi thích học tiếng Trung.')
            ->and($first->vi_attempts)->toBe(0)
            ->and($second->refresh()->translation_vi)->toBeNull()
            ->and($second->vi_attempts)->toBe(1);
    });

    it('bỏ qua chỉ số nằm ngoài lô thay vì đoán', function (): void {
        $example = addTranslatableExample($this->word);

        Http::fake(['*' => Http::response(exampleTranslationResponse([
            ['i' => 7, 'vi' => 'Một câu nào đó.'],
        ]))]);

        runTranslateJob($this->word->id);

        expect($example->refresh()->translation_vi)->toBeNull();
    });

    it('không đếm hỏng khi thiếu key Gemini', function (): void {
        // Cắm key vào sau phải chạy được; đếm hỏng ở đây là giết câu vĩnh viễn.
        config(['services.gemini.key' => null]);
        $example = addTranslatableExample($this->word);

        Http::fake();

        runTranslateJob($this->word->id);

        expect($example->refresh()->vi_attempts)->toBe(0);
        Http::assertNothingSent();
    });

    it('khoá chống dẫm chân và khoá đó tự hết hạn', function (): void {
        /*
         * `ShouldBeUnique` chặn 50 người mở cùng một từ đẩy 50 job trùng nhau.
         *
         * `uniqueFor` là nửa còn lại: thiếu nó thì một job biến mất giữa chừng
         * để lại khoá vĩnh viễn, và từ đó KHÔNG BAO GIỜ xếp hàng lại được.
         */
        $job = new TranslateWordExamples($this->word->id);

        expect($job)->toBeInstanceOf(ShouldBeUnique::class)
            ->and($job->uniqueId())->toBe((string) $this->word->id)
            ->and($job->uniqueFor)->toBeGreaterThan(0);
    });
});

it('không đổi shape của word detail', function (): void {
    // Response chi tiết từ cache `public` 24h — nhét một trường điền lười vào nó
    // là đóng băng `null` trên CDN cho mọi từ chưa ai mở.
    addTranslatableExample($this->word, [
        'translation_vi' => 'Tôi thích học tiếng Trung.',
        'vi_version' => ExampleTranslationPrompt::VERSION,
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/dictionary/words/{$this->word->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.examples.0.translation_vi');
});
