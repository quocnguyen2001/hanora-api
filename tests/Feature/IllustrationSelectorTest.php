<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Services\Illustration\IllustrationSelection;
use App\Services\Illustration\IllustrationSelector;
use Illuminate\Support\Facades\Http;

/**
 * Cổng chặn liên quan — phần khó nhất của lớp ảnh minh hoạ.
 *
 * Fixture chụp từ API THẬT ngày 2026-08-29. Chúng khoá LUẬT, không khoá kho ảnh
 * của Pixabay: kho ảnh đổi thì số liệu thật sẽ lệch khỏi fixture, và đó là điều
 * bình thường. Đừng viết test gọi mạng thật ở đây.
 */
beforeEach(function (): void {
    config([
        'services.pixabay.key' => 'test-key',
        'services.pixabay.min_total_hits' => 200,
        'services.pixabay.min_tag_matches' => 3,
        'services.pixabay.sample_size' => 5,
    ]);
});

/**
 * @return array<string, mixed>
 */
function pixabayFixture(string $name): array
{
    $path = base_path("tests/Fixtures/pixabay/{$name}.json");

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Ghép phản hồi theo `lang`, vì cổng chặn dựa vào việc hai nhánh ngôn ngữ trả
 * kết quả KHÁC nhau — fake chung một phản hồi sẽ vô hiệu hoá chính thứ đang test.
 */
function fakePixabay(string $zhFixture, ?string $enFixture): void
{
    Http::fake(function ($request) use ($zhFixture, $enFixture) {
        $lang = $request->data()['lang'] ?? 'zh';

        if ($lang === 'en') {
            return $enFixture === null
                ? Http::response(['totalHits' => 0, 'hits' => []])
                : Http::response(pixabayFixture($enFixture));
        }

        return Http::response(pixabayFixture($zhFixture));
    });
}

function wordFor(string $simplified, string $gloss): DictionaryWord
{
    return DictionaryWord::factory()->create([
        'simplified' => $simplified,
        'definitions_en' => [$gloss],
    ]);
}

function selectFor(DictionaryWord $word): IllustrationSelection
{
    return app(IllustrationSelector::class)->select($word);
}

it('chọn được ảnh khi cả hai ngôn ngữ cùng qua cổng', function (): void {
    fakePixabay('zh-pingguo', 'en-apple');

    $selection = selectFor(wordFor('苹果', 'apple'));

    expect($selection->candidate)->not->toBeNull()
        ->and($selection->gateClosed)->toBeFalse()
        ->and($selection->candidate->matchedQuery)->toBe('苹果');
});

it('suy ra URL _640 ổn định trên CDN, không dùng webformatURL', function (): void {
    fakePixabay('zh-pingguo', 'en-apple');

    $url = selectFor(wordFor('苹果', 'apple'))->candidate->imageUrl;

    expect($url)->toStartWith('https://cdn.pixabay.com/photo/')
        ->and($url)->toEndWith('_640.jpg')
        // `webformatURL` hết hạn sau 24 giờ — lưu nó là cache một thứ tự huỷ.
        ->and($url)->not->toContain('/get/');
});

it('ghi lại tác giả và trang nguồn để còn ghi công theo ToS', function (): void {
    fakePixabay('zh-pingguo', 'en-apple');

    $candidate = selectFor(wordFor('苹果', 'apple'))->candidate;

    expect($candidate->author)->not->toBeNull()
        ->and($candidate->pageUrl)->toStartWith('https://pixabay.com/')
        ->and($candidate->authorUrl)->toContain('/users/');
});

/**
 * CA HỒI QUY QUAN TRỌNG NHẤT CỦA FILE NÀY.
 *
 * `可能` QUA được cổng tiếng Trung — totalHits=500, 3/5 tag khớp nguyên token —
 * và ảnh đầu là một con chim (`开普敦的可能莺`, Cape May Warbler), vì tag tiếng
 * Trung của Pixabay dịch máy nên "May" thành `可能`.
 *
 * Nhánh tiếng Anh (`possible`, 67 totalHits) là thứ DUY NHẤT đóng được ca này.
 * Ai đó "tối ưu" bỏ nhánh thứ hai thì test này phải đỏ.
 */
it('đóng cổng với 可能 dù nhánh tiếng Trung đã qua', function (): void {
    fakePixabay('zh-keneng', 'en-possible');

    $selection = selectFor(wordFor('可能', 'possible'));

    expect($selection->gateClosed)->toBeTrue()
        ->and($selection->candidate)->toBeNull()
        // `none` là kết quả ĐÚNG, không phải lỗi — job không được tăng attempts.
        ->and($selection->reason)->toBeNull();
});

it('vẫn gọi nhánh tiếng Anh khi nhánh tiếng Trung qua cổng', function (): void {
    fakePixabay('zh-keneng', 'en-possible');

    selectFor(wordFor('可能', 'possible'));

    Http::assertSentCount(2);
});

/**
 * CA HỒI QUY: khớp tag phải là NGUYÊN TOKEN.
 *
 * `的` là hư từ và nằm lọt trong hầu hết tag ghép tiếng Trung. Đổi luật khớp
 * sang `str_contains` sẽ làm ca này đỏ — đó là mục đích của nó.
 */
it('đóng cổng với hư từ 的', function (): void {
    fakePixabay('zh-de', 'en-of');

    expect(selectFor(wordFor('的', 'of'))->gateClosed)->toBeTrue();
});

/**
 * CA CÔ LẬP luật khớp nguyên token.
 *
 * Ca `的` ở trên KHÔNG đủ để khoá luật này: ở đó nhánh tiếng Anh (`of`, 0 hit)
 * cũng đóng cổng, nên đổi sang `str_contains` mà test vẫn xanh — nó pass vì lý
 * do khác với lý do nó tuyên bố.
 *
 * Ở đây nhánh tiếng Anh được cho QUA hẳn, nên thứ duy nhất còn đóng được cổng
 * là luật khớp nguyên token ở nhánh tiếng Trung. Fixture `zh-de` khớp CHUỖI CON
 * 5/5 nhưng khớp NGUYÊN TOKEN 0/5 — đúng cái bẫy mà một chữ Hán đơn tạo ra.
 */
it('đóng cổng khi tag chỉ khớp chuỗi con chứ không khớp nguyên token', function (): void {
    fakePixabay('zh-de', 'en-apple');

    expect(selectFor(wordFor('的', 'apple'))->gateClosed)->toBeTrue();
});

it('không tốn request tiếng Anh khi nhánh tiếng Trung đã trượt', function (): void {
    // Hư từ chiếm phần lớn số lần đóng cổng, nên thoát sớm ở đây tiết kiệm đúng
    // một request cho đúng nhóm từ hay bị mở nhất.
    fakePixabay('zh-de', 'en-of');

    selectFor(wordFor('的', 'of'));

    Http::assertSentCount(1);
});

it('đóng cổng khi totalHits dưới ngưỡng', function (): void {
    Http::fake(['pixabay.com/*' => Http::response(['totalHits' => 12, 'hits' => []])]);

    expect(selectFor(wordFor('但是', 'but'))->gateClosed)->toBeTrue();
});

it('đóng cổng khi gloss tiếng Anh dài hơn ba từ', function (): void {
    // Gloss dài không bao giờ là một tag Pixabay — đóng luôn thay vì tốn một
    // request để nhận 0 hit.
    fakePixabay('zh-pingguo', 'en-apple');

    $word = wordFor('苹果', 'a kind of round sweet fruit');

    expect(selectFor($word)->gateClosed)->toBeTrue();
    Http::assertSentCount(1);
});

/**
 * CA HỒI QUY: CC-CEDICT gộp nhiều nghĩa vào MỘT chuỗi, ngăn bằng dấu chấm phẩy.
 *
 * Phát hiện khi chạy thử trên DB thật: `医生` là `"doctor; medical practitioner"`.
 * Tra nguyên chuỗi đó trả 0 hit và đóng cổng oan một trong những từ cụ thể nhất
 * có thể có ảnh minh hoạ.
 */
it('lấy nghĩa đầu tiên khi gloss gộp nhiều nghĩa bằng dấu chấm phẩy', function (): void {
    fakePixabay('zh-pingguo', 'en-apple');

    selectFor(wordFor('苹果', 'apple; pome fruit'));

    Http::assertSent(function ($request): bool {
        return ($request->data()['lang'] ?? '') !== 'en'
            || $request->data()['q'] === 'apple';
    });
});

it('gỡ tiền tố "to " và chú thích trong ngoặc khỏi gloss', function (): void {
    fakePixabay('zh-pingguo', 'en-apple');

    selectFor(wordFor('苹果', 'to apple (fruit)'));

    Http::assertSent(function ($request): bool {
        return ($request->data()['lang'] ?? '') !== 'en'
            || $request->data()['q'] === 'apple';
    });
});

it('đóng cổng khi previewURL sai hình dạng, không đoán size khác', function (): void {
    // `_340` trả 403 trên CDN thật — suy ra size ngoài `_640` là đoán mò.
    $broken = pixabayFixture('zh-pingguo');
    foreach ($broken['hits'] as $i => $hit) {
        $broken['hits'][$i]['previewURL'] = 'https://cdn.pixabay.com/photo/x/apples-1_340.jpg';
    }

    Http::fake(function ($request) use ($broken) {
        return ($request->data()['lang'] ?? 'zh') === 'en'
            ? Http::response(pixabayFixture('en-apple'))
            : Http::response($broken);
    });

    expect(selectFor(wordFor('苹果', 'apple'))->gateClosed)->toBeTrue();
});

it('báo throttled chứ không phải gate đóng khi chạm 429', function (): void {
    // Trộn hai thứ này lại là biến một lần chạm trần nhịp độ thành "từ này vĩnh
    // viễn không có ảnh".
    Http::fake(['pixabay.com/*' => Http::response('rate limited', 429, ['X-RateLimit-Reset' => '30'])]);

    $selection = selectFor(wordFor('苹果', 'apple'));

    expect($selection->throttled)->toBeTrue()
        ->and($selection->gateClosed)->toBeFalse()
        ->and($selection->retryAfter)->toBe(30);
});

it('báo failed chứ không phải gate đóng khi API hỏng', function (): void {
    Http::fake(['pixabay.com/*' => Http::response('boom', 500)]);

    $selection = selectFor(wordFor('苹果', 'apple'));

    expect($selection->gateClosed)->toBeFalse()
        ->and($selection->reason)->toBe('http_500');
});
