<?php

declare(strict_types=1);

use App\Models\DictionarySentence;
use App\Models\DictionaryWord;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);

    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-3.1-flash-lite']);
    $this->user = User::factory()->create();
});

/**
 * @param  list<array<string, string>>  $tokens
 */
function sentenceResponse(array $tokens, array $override = []): array
{
    return [
        'usage' => ['total_input_tokens' => 200, 'total_output_tokens' => 400],
        'steps' => [
            ['type' => 'thought', 'signature' => 'x'],
            ['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => json_encode(array_merge([
                    'pinyin' => 'wǒ xǐhuān xuéxí',
                    'vi' => 'Tôi thích học.',
                    'literal_vi' => 'tôi thích học tập',
                    'tokens' => $tokens,
                    'grammar_notes' => ['喜欢 đứng trước động từ.'],
                ], $override))],
            ]],
        ],
    ];
}

function sentenceApi(string $zh): TestResponse
{
    return test()->actingAs(test()->user, 'sanctum')
        ->getJson('/api/dictionary/sentences?'.http_build_query(['zh' => $zh]));
}

/** Tách đúng, nối lại ra nguyên câu `我喜欢学习。` */
function goodTokens(): array
{
    return [
        ['zh' => '我', 'pinyin' => 'wǒ', 'vi' => 'tôi'],
        ['zh' => '喜欢', 'pinyin' => 'xǐhuān', 'vi' => 'thích'],
        ['zh' => '学习', 'pinyin' => 'xuéxí', 'vi' => 'học'],
        ['zh' => '。', 'pinyin' => '.', 'vi' => ''],
    ];
}

it('yêu cầu đăng nhập', function (): void {
    Http::fake();

    $this->getJson('/api/dictionary/sentences?zh=%E5%AD%A6%E4%B9%A0')->assertUnauthorized();
    Http::assertNothingSent();
});

describe('kiểm đầu vào', function (): void {
    it('từ chối chuỗi không có chữ Hán', function (): void {
        // Không có luật này thì endpoint thành API dịch tổng quát: gõ gì cũng
        // được nhận và trả tiền cho Gemini.
        Http::fake();

        sentenceApi('toi muon hoc')->assertStatus(422);
        Http::assertNothingSent();
    });

    it('từ chối câu quá dài', function (): void {
        Http::fake();

        sentenceApi(str_repeat('学', 201))->assertStatus(422);
        Http::assertNothingSent();
    });

    it('từ chối chuỗi rỗng', function (): void {
        Http::fake();

        sentenceApi('   ')->assertStatus(422);
    });
});

describe('phân tích câu', function (): void {
    it('trả câu, pinyin, nghĩa đen, tách từ và ghi chú', function (): void {
        Http::fake(['*' => Http::response(sentenceResponse(goodTokens()))]);

        $data = sentenceApi('我喜欢学习。')->assertOk()->json('data');

        expect($data['zh'])->toBe('我喜欢学习。')
            ->and($data['pinyin'])->toBe('wǒ xǐhuān xuéxí')
            ->and($data['vi'])->toBe('Tôi thích học.')
            ->and($data['literal_vi'])->toBe('tôi thích học tập')
            ->and($data['grammar_notes'])->toBe(['喜欢 đứng trước động từ.'])
            // Nhãn nguồn bắt buộc: toàn bộ trang này do máy sinh.
            ->and($data['source'])->toBe('ai');
    });

    it('gắn word_id thật để bấm sang chi tiết từ', function (): void {
        // Đây là thứ khiến trang này hơn một khối văn bản: mỗi từ trong câu là
        // một lối vào từ điển.
        $expected = DictionaryWord::where('simplified', '学习')->value('id');
        Http::fake(['*' => Http::response(sentenceResponse(goodTokens()))]);

        $tokens = sentenceApi('我喜欢学习。')->assertOk()->json('data.tokens');

        expect($tokens)->toHaveCount(4)
            ->and(collect($tokens)->firstWhere('zh', '学习')['word_id'])->toBe($expected)
            // Dấu câu không có trong từ điển — `null` là đúng, FE không cho bấm.
            ->and(collect($tokens)->firstWhere('zh', '。')['word_id'])->toBeNull();
    });

    it('cache vĩnh viễn — lần hai không gọi Gemini', function (): void {
        Http::fake(['*' => Http::response(sentenceResponse(goodTokens()))]);

        sentenceApi('我喜欢学习。')->assertOk();
        sentenceApi('我喜欢学习。')->assertOk();

        Http::assertSentCount(1);
    });

    it('gộp câu chỉ khác khoảng trắng thành một bản ghi', function (): void {
        Http::fake(['*' => Http::response(sentenceResponse(goodTokens()))]);

        sentenceApi('我喜欢学习。')->assertOk();
        sentenceApi('  我喜欢学习。  ')->assertOk();

        expect(DictionarySentence::count())->toBe(1);
        Http::assertSentCount(1);
    });

    it('cache dài ở tầng HTTP', function (): void {
        Http::fake(['*' => Http::response(sentenceResponse(goodTokens()))]);

        sentenceApi('我喜欢学习。')->assertHeader('Cache-Control', 'max-age=86400, public');
    });
});

describe('chốt chặn tách từ', function (): void {
    it('bỏ tách từ khi model làm rơi mất một chữ', function (): void {
        /*
         * Đây là ca nguy hiểm nhất: người học đọc một câu KHÁC với câu trên màn
         * hình mà không cách nào thấy bằng mắt. Thà mất khối tách từ.
         */
        Http::fake(['*' => Http::response(sentenceResponse([
            ['zh' => '我', 'pinyin' => 'wǒ', 'vi' => 'tôi'],
            ['zh' => '学习', 'pinyin' => 'xuéxí', 'vi' => 'học'],
        ]))]);

        $data = sentenceApi('我喜欢学习。')->assertOk()->json('data');

        expect($data['tokens'])->toBe([])
            // Nhưng pinyin và bản dịch vẫn còn — chúng không liên quan gì tới lỗi tách.
            ->and($data['vi'])->toBe('Tôi thích học.');
    });

    it('bỏ tách từ khi model thêm chữ không có trong câu', function (): void {
        Http::fake(['*' => Http::response(sentenceResponse([
            ...goodTokens(),
            ['zh' => '好', 'pinyin' => 'hǎo', 'vi' => 'tốt'],
        ]))]);

        expect(sentenceApi('我喜欢学习。')->assertOk()->json('data.tokens'))->toBe([]);
    });

    it('CHẤP NHẬN dấu câu khác bề rộng', function (): void {
        /*
         * Đo 5 lần gọi thật: 1 lần model trả `?` nửa chiều thay cho `？` toàn
         * chiều. Bề rộng dấu câu là TRÌNH BÀY — vứt cả phần tách từ vì nó là
         * đánh đổi sai, và bản đầu của code này đã mắc đúng lỗi đó.
         */
        Http::fake(['*' => Http::response(sentenceResponse([
            ['zh' => '你', 'pinyin' => 'nǐ', 'vi' => 'bạn'],
            ['zh' => '好', 'pinyin' => 'hǎo', 'vi' => 'tốt'],
            ['zh' => '?', 'pinyin' => '?', 'vi' => ''],
        ]))]);

        expect(sentenceApi('你好？')->assertOk()->json('data.tokens'))->toHaveCount(3);
    });
});

describe('suy giảm êm, không bao giờ 5xx', function (): void {
    it('trả 200 data:null khi Gemini lỗi', function (): void {
        Http::fake(['*' => Http::response('boom', 500)]);

        sentenceApi('我喜欢学习。')->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.status', 'unavailable')
            // Hỏng là tạm thời; để CDN đóng băng 24 giờ thì sự cố 30 giây thành một ngày.
            ->assertHeader('Cache-Control', 'no-store, private');
    });

    it('trả 200 data:null khi thiếu pinyin lẫn bản dịch', function (): void {
        Http::fake(['*' => Http::response(sentenceResponse(goodTokens(), ['pinyin' => '', 'vi' => '']))]);

        sentenceApi('我喜欢学习。')->assertOk()->assertJsonPath('data', null);
    });

    it('dừng gọi sau MAX_ATTEMPTS lần hỏng', function (): void {
        Http::fake(['*' => Http::response('boom', 500)]);

        foreach (range(1, DictionarySentence::MAX_ATTEMPTS + 2) as $ignored) {
            sentenceApi('我喜欢学习。')->assertOk();
        }

        Http::assertSentCount(DictionarySentence::MAX_ATTEMPTS);
        expect(DictionarySentence::first()->attempts)->toBe(DictionarySentence::MAX_ATTEMPTS);
    });

    it('không gọi và không đếm là thất bại khi lớp AI tắt', function (): void {
        // Tắt là trạng thái cấu hình. Đếm nó thành thất bại thì cắm key vào rồi
        // câu vẫn chết vĩnh viễn.
        config(['services.gemini.key' => '']);
        Http::fake();

        sentenceApi('我喜欢学习。')->assertOk()->assertJsonPath('data', null);

        Http::assertNothingSent();
        expect(DictionarySentence::count())->toBe(0);
    });
});
