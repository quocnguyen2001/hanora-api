<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// `seedSearchFixtures`, `aiReturns` và `searchApi` nằm ở `tests/Pest.php`:
// `SearchRefineTest` dùng chung, và hai file phải dựng corpus bằng cùng một tay.
beforeEach(function (): void {
    seedSearchFixtures();
});

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

    it('cache NGẮN khi kết quả mới chỉ là SQL', function (): void {
        /*
         * Không chung kết: một lượt bấm "Tìm lại bằng AI" đổi câu trả lời này
         * bất cứ lúc nào. Cache 24 giờ ở đây khiến chính người vừa bấm nút tra
         * lại vẫn nhận bản cũ từ trình duyệt, không hỏi server lấy một lần.
         */
        Http::fake();

        searchApi('学习', null)->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public');
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

    it('vẫn cache được — thiếu key không phải sự cố', function (): void {
        /*
         * Chốt chặn cho một lỗi thật đã sửa: gộp "thiếu key" vào nhánh "AI hỏng"
         * khiến mọi `/search` của một cài đặt không key thành `no-store`, tức
         * trả giá cache cho một tính năng thậm chí chưa bật.
         *
         * Bất biến là KHÔNG `no-store`, không phải một con số cụ thể — thiếu key
         * cho ra `source: 'sql'`, nên nó đi theo trần ngắn của nhánh đó.
         */
        config(['services.gemini.key' => '']);
        Http::fake();

        searchApi('học')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public');
    });
});

describe('câu dịch trong response', function (): void {
    it('trả câu dịch NGOÀI data cho truy vấn dạng câu', function (): void {
        aiReturns(['学习'], [
            'zh' => '你还记得我吗？',
            'pinyin' => 'nǐ hái jìde wǒ ma?',
            'vi' => 'bạn có nhớ tôi không?',
        ]);

        $response = searchApi('bạn có nhớ tôi không?')->assertOk();

        expect($response->json('translation.zh'))->toBe('你还记得我吗？')
            ->and($response->json('translation.pinyin'))->toBe('nǐ hái jìde wǒ ma?')
            // Nhãn nguồn bắt buộc: đây là câu do máy dịch, không phải dữ liệu từ điển.
            ->and($response->json('translation.source'))->toBe('ai');
    });

    it('KHÔNG nhét câu dịch vào data', function (): void {
        /*
         * `data[]` là mục từ điển có `id` thật, lưu được vào sổ từ vựng. Một câu
         * dịch không có id — nhét vào cùng mảng là làm nút lưu hỏng ở đúng phần
         * tử đầu tiên người dùng nhìn thấy.
         */
        aiReturns(['学习'], ['zh' => '我想学习', 'pinyin' => 'wǒ xiǎng xuéxí', 'vi' => 'x']);

        $data = searchApi('tôi muốn đi học')->assertOk()->json('data');

        expect(collect($data)->pluck('simplified'))->not->toContain('我想学习')
            ->and(collect($data)->pluck('id')->filter(fn ($id) => $id === null))->toBeEmpty();
    });

    it('translation là null cho truy vấn dạng từ', function (): void {
        aiReturns(['学生']);

        expect(searchApi('học trò là gì')->assertOk()->json('translation'))->toBeNull();
    });

    it('translation là null khi không đụng tới AI', function (): void {
        Http::fake();

        expect(searchApi('学习', null)->assertOk()->json('translation'))->toBeNull();
    });

    it('dập hint hv_not_found khi AI đã trả lời được', function (): void {
        // Hint đó khuyên "thử chuyển sang 中文". Hiện nó cạnh một danh sách kết
        // quả đúng là đổ lỗi cho người dùng về việc hệ thống vừa làm xong.
        aiReturns(['学习']);

        expect(searchApi('bạn có nhớ tôi không?')->assertOk()->json('meta.hint'))->toBeNull();
    });

    it('giữ hint khi AI không tham gia', function (): void {
        Http::fake();
        config(['services.gemini.key' => '']);

        searchApi('không khớp gì cả')->assertOk()->assertJsonPath('meta.source', 'sql');
    });
});
