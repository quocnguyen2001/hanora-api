<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\SearchQueryInterpretation;
use Illuminate\Support\Facades\Http;

/*
 * Đường tra THƯỜNG đọc diễn giải đã có.
 *
 * `SearchWeakness` vẫn quyết định có TIÊU TIỀN hay không; nó thôi quyết định có
 * được ĐỌC hay không. Vì thế mọi test ở đây dùng `学习` — ca SQL mạnh, khớp chữ
 * Hán chính xác — và khẳng định `Http::assertNothingSent()`: nếu một lời gọi
 * Gemini lọt ra ở nhánh này thì thay đổi đã đi quá xa mục đích của nó.
 */
beforeEach(function (): void {
    seedSearchFixtures();
});

/** Dòng cache như thể ai đó đã bấm "Tìm lại bằng AI" cho truy vấn này. */
function cacheRow(string $query, string $mode, array $words, ?array $translation = null): void
{
    $ids = DictionaryWord::query()->whereIn('simplified', $words)->pluck('id')->all();

    SearchQueryInterpretation::query()->create([
        'query_normalized' => $query,
        'mode' => $mode,
        'word_ids' => $ids,
        'translation' => $translation,
        'model' => 'gemini-3.1-flash-lite',
        'prompt_version' => 1,
    ]);
}

describe('SQL mạnh vẫn dùng diễn giải đã có', function (): void {
    it('đưa từ của AI lên đầu mà KHÔNG gọi Gemini', function (): void {
        Http::fake();
        cacheRow('学习', 'auto', ['学生']);

        $response = searchApi('学习', null);

        $response->assertOk()->assertJsonPath('meta.source', 'ai');
        expect($response->json('data.0.simplified'))->toBe('学生');
        Http::assertNothingSent();
    });

    it('giữ nguyên hành vi cũ khi chưa ai từng hỏi truy vấn này', function (): void {
        // Bất biến quan trọng nhất: không có dòng cache thì mọi thứ y như trước.
        Http::fake();

        searchApi('学习', null)->assertOk()->assertJsonPath('meta.source', 'sql');

        Http::assertNothingSent();
    });

    it('không đổi gì khi dòng cache rỗng', function (): void {
        /*
         * `word_ids` rỗng là một CÂU TRẢ LỜI thật — "AI bảo không có gì" — chứ
         * không phải cache miss. Nó không được phép biến `source` thành `ai` khi
         * chẳng có từ nào để đóng góp.
         */
        Http::fake();
        cacheRow('学习', 'auto', []);

        searchApi('学习', null)->assertOk()->assertJsonPath('meta.source', 'sql');
    });

    it('trả cả câu dịch nằm trong dòng cache', function (): void {
        Http::fake();
        cacheRow('学习', 'auto', ['学生'], ['zh' => '我要学习', 'pinyin' => 'wǒ yào xuéxí', 'vi' => 'tôi muốn học']);

        searchApi('学习', null)->assertOk()->assertJsonPath('translation.zh', '我要学习');
    });

    it('không đọc cache ở trang 2', function (): void {
        Http::fake();
        cacheRow('学习', 'auto', ['学生']);

        searchApi('学习', null, page: 2)->assertOk()->assertJsonPath('meta.source', 'sql');
    });
});

describe('phép đếm và cấu hình', function (): void {
    it('tăng hit_count khi đường thường trúng cache', function (): void {
        /*
         * `dictionary:search-stats` đọc con số này để trả lời "lớp AI có đáng
         * tiền không". Đường mới mà quên đếm thì tỉ lệ trúng cache báo sai.
         */
        Http::fake();
        cacheRow('学习', 'auto', ['学生']);

        searchApi('学习', null)->assertOk();

        expect(SearchQueryInterpretation::query()->first()->hit_count)->toBe(1);
    });

    it('vẫn phục vụ từ cache khi KHÔNG cấu hình key', function (): void {
        /*
         * Đọc một dòng đã trả tiền rồi thì không cần key. Phần quan trọng của
         * "thiếu key thì lớp AI tắt êm" vẫn giữ: không có lời gọi mạng nào.
         */
        Http::fake();
        config(['services.gemini.key' => '']);
        cacheRow('学习', 'auto', ['学生']);

        searchApi('学习', null)->assertOk()->assertJsonPath('meta.source', 'ai');

        Http::assertNothingSent();
    });
});

it('một lượt refine sửa kết quả cho mọi lần tra sau', function (): void {
    // Kịch bản đầu-cuối, và là toàn bộ lý do plan này tồn tại.
    aiReturns(['学生']);

    searchApi('学习', null, refine: 'ai')->assertOk()->assertJsonPath('meta.source', 'ai');

    // Lần tra THƯỜNG ngay sau đó — không nút, không refine.
    $response = searchApi('学习', null);

    $response->assertOk()->assertJsonPath('meta.source', 'ai');
    expect($response->json('data.0.simplified'))->toBe('学生');

    // Đúng MỘT lời gọi Gemini cho cả hai lượt.
    Http::assertSentCount(1);
});
