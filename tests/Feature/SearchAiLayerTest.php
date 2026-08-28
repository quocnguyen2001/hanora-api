<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
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

    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-3.1-flash-lite']);
    $this->user = User::factory()->create();
});

function aiReturns(array $words): void
{
    Http::fake(['*' => Http::response([
        'usage' => ['total_input_tokens' => 40, 'total_output_tokens' => 20],
        'steps' => [
            ['type' => 'thought', 'signature' => 'x'],
            ['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => json_encode(['words' => $words])],
            ]],
        ],
    ])]);
}

function searchApi(string $q, ?string $mode = 'vi', int $page = 1): TestResponse
{
    $query = array_filter(['q' => $q, 'mode' => $mode, 'page' => $page]);

    return test()->actingAs(test()->user, 'sanctum')
        ->getJson('/api/dictionary/search?'.http_build_query($query));
}

describe('không gọi AI khi SQL đã có bằng chứng mạnh', function (): void {
    it('bỏ qua AI với truy vấn chữ Hán', function (): void {
        Http::fake();

        $response = searchApi('学习', null);

        $response->assertOk()->assertJsonPath('meta.source', 'sql');
        Http::assertNothingSent();
    });

    it('bỏ qua AI khi khớp pinyin chính xác', function (): void {
        Http::fake();

        searchApi('xuexi', 'cn')->assertOk()->assertJsonPath('meta.source', 'sql');
        Http::assertNothingSent();
    });

    it('không gọi AI ở trang 2 dù truy vấn yếu', function (): void {
        // Xếp hạng của AI là khái niệm của trang đầu. Trả tiền cho mỗi trang là
        // trả tiền nhiều lần cho cùng một câu trả lời.
        Http::fake();

        searchApi('không khớp gì cả', 'vi', page: 2)->assertOk();
        Http::assertNothingSent();
    });
});

describe('gọi AI khi SQL yếu', function (): void {
    it('đưa kết quả AI lên đầu và đánh dấu source', function (): void {
        aiReturns(['学生', '学习']);

        $response = searchApi('học trò là gì');

        $response->assertOk()->assertJsonPath('meta.source', 'ai');
        expect($response->json('data.0.simplified'))->toBe('学生');
    });

    it('cứu được truy vấn mà SQL trả rỗng', function (): void {
        aiReturns(['学习']);

        $response = searchApi('tôi muốn đi học vào ngày mai');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.simplified'))->toBe('学习')
            // `total` phải khớp thứ đang hiển thị, không được báo 0 kèm 1 dòng.
            ->and($response->json('meta.total'))->toBe(1);
    });

    it('không gọi mạng lần thứ hai cho cùng truy vấn', function (): void {
        aiReturns(['学习']);

        searchApi('tôi muốn đi học')->assertOk();
        searchApi('tôi muốn đi học')->assertOk();

        Http::assertSentCount(1);
    });

    it('giữ kết quả SQL phía sau kết quả AI', function (): void {
        // Trộn, không thay thế: 学生会/学生证 là thứ SQL tìm được mà AI không nghĩ tới.
        aiReturns(['学习']);

        $data = searchApi('học')->assertOk()->json('data');

        expect($data[0]['simplified'])->toBe('学习')
            ->and(count($data))->toBeGreaterThan(1);
    });
});

describe('Gemini hỏng thì rơi về SQL, không bao giờ 5xx', function (): void {
    it('trả kết quả SQL khi API lỗi 500', function (): void {
        Http::fake(['*' => Http::response('boom', 500)]);

        $response = searchApi('học');

        $response->assertOk()->assertJsonPath('meta.source', 'sql');
        expect($response->json('data'))->not->toBeEmpty();
    });

    it('trả kết quả SQL khi mạng chết', function (): void {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        searchApi('học')->assertOk()->assertJsonPath('meta.source', 'sql');
    });

    it('đặt no-store khi đã thử AI mà hỏng', function (): void {
        // Không có dòng này, một sự cố 30 giây bị CDN đóng băng thành 24 giờ.
        Http::fake(['*' => Http::response('boom', 500)]);

        // Symfony sắp lại directive theo alphabet và tự thêm `private` cho
        // `no-store`, nên khẳng định theo giá trị nó THỰC SỰ phát ra.
        searchApi('học')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    });

    it('vẫn cache dài khi đường AI chạy trơn', function (): void {
        aiReturns(['学习']);

        searchApi('học')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, public');
    });

    it('vẫn cache dài khi không đụng tới AI', function (): void {
        Http::fake();

        searchApi('学习', null)->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, public');
    });
});

it('loại chữ AI bịa trước khi trả về', function (): void {
    aiReturns(['这个词不存在', '学习']);

    $data = searchApi('học')->assertOk()->json('data');

    expect(collect($data)->pluck('simplified'))->not->toContain('这个词不存在')
        ->and($data[0]['simplified'])->toBe('学习');
});

it('không rò trường theo user vào response cache public', function (): void {
    // Quy ước bảo mật của `WordSearchResultResource` phải giữ nguyên sau khi
    // thêm lớp AI: response này cache `public` ở cả HTTP lẫn service worker.
    aiReturns(['学习']);

    $keys = array_keys(searchApi('học')->assertOk()->json('data.0'));

    expect($keys)->not->toContain('saved')->not->toContain('user_word_id');
});

it('vẫn yêu cầu đăng nhập', function (): void {
    Http::fake();

    test()->getJson('/api/dictionary/search?q=học&mode=vi')->assertUnauthorized();
    Http::assertNothingSent();
});

it('giữ nguyên id thật của DictionaryWord trong kết quả AI', function (): void {
    $expected = DictionaryWord::where('simplified', '学习')->value('id');
    aiReturns(['学习']);

    expect(searchApi('tôi muốn đi học')->json('data.0.id'))->toBe($expected);
});

describe('không cấu hình key thì lớp AI tắt êm', function (): void {
    it('vẫn trả kết quả SQL và KHÔNG gọi mạng', function (): void {
        config(['services.gemini.key' => '']);
        Http::fake();

        searchApi('học')->assertOk()->assertJsonPath('meta.source', 'sql');
        Http::assertNothingSent();
    });

    it('vẫn cache dài — thiếu key không phải sự cố', function (): void {
        /*
         * Chốt chặn cho một lỗi thật đã sửa: gộp "thiếu key" vào nhánh "AI hỏng"
         * khiến mọi `/search` của một cài đặt không key thành `no-store`, tức
         * trả giá cache cho một tính năng thậm chí chưa bật.
         */
        config(['services.gemini.key' => '']);
        Http::fake();

        searchApi('học')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=86400, public');
    });
});
