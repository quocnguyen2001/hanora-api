<?php

declare(strict_types=1);

use App\Services\Illustration\PixabayClient;
use App\Services\Illustration\PixabayResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Hợp đồng của class này là "KHÔNG BAO GIỜ ném exception".
 *
 * Mỗi ca dưới đây là một cách Pixabay có thể hỏng trong thực tế. Nếu một trong
 * số chúng ném ra ngoài, màn chi tiết từ sẽ trả 500 vì một hình minh hoạ —
 * đúng thứ mà khuôn suy giảm êm của `GeminiClient` sinh ra để tránh.
 */
beforeEach(function (): void {
    config([
        'services.pixabay.key' => 'test-key',
        'services.pixabay.timeout' => 5,
        'services.pixabay.sample_size' => 5,
    ]);
});

function callPixabay(string $query = '苹果', string $lang = 'zh'): PixabayResult
{
    return app(PixabayClient::class)->search($query, $lang);
}

/**
 * Hình dạng response thật, rút gọn từ một lời gọi trực tiếp ngày 2026-08-29.
 *
 * @return array<string, mixed>
 */
function pixabayResponse(int $totalHits = 500, int $hitCount = 5): array
{
    return [
        'total' => 16128,
        'totalHits' => $totalHits,
        'hits' => array_map(fn (int $i): array => [
            'id' => 2788599 + $i,
            'tags' => '苹果, 红苹果, 成熟',
            'previewURL' => "https://cdn.pixabay.com/photo/2017/09/26/13/21/apples-{$i}_150.jpg",
            'webformatURL' => "https://pixabay.com/get/g31f7df0b79c39b0b_{$i}_640.jpg",
            'webformatWidth' => 640,
            'webformatHeight' => 426,
            'pageURL' => "https://pixabay.com/zh/photos/apples-{$i}/",
            'user' => 'NoName_13',
            'user_id' => 2364555,
        ], range(1, $hitCount)),
    ];
}

it('trả hits và totalHits khi Pixabay phản hồi bình thường', function (): void {
    Http::fake(['pixabay.com/*' => Http::response(pixabayResponse())]);

    $result = callPixabay();

    expect($result->successful)->toBeTrue()
        ->and($result->throttled)->toBeFalse()
        ->and($result->totalHits)->toBe(500)
        ->and($result->hits)->toHaveCount(5);
});

it('gửi đúng tham số lọc mà cổng chặn dựa vào', function (): void {
    Http::fake(['pixabay.com/*' => Http::response(pixabayResponse())]);

    callPixabay('苹果', 'zh');

    Http::assertSent(function ($request): bool {
        // `lang` quyết định tag trả về thuộc ngôn ngữ nào — đó chính là thứ
        // cổng chặn đối chiếu, nên gửi sai `lang` làm hỏng toàn bộ cơ chế.
        return $request['lang'] === 'zh'
            && $request['q'] === '苹果'
            && $request['image_type'] === 'photo'
            && $request['safesearch'] === 'true';
    });
});

it('tách 429 thành `throttled`, không phải `failed`', function (): void {
    // Phân biệt này là lý do `PixabayResult` có ba trạng thái: job phải
    // `release()` mà KHÔNG tăng `attempts` khi chạm trần nhịp độ.
    Http::fake(['pixabay.com/*' => Http::response('rate limit exceeded', 429, [
        'X-RateLimit-Reset' => '42',
    ])]);

    $result = callPixabay();

    expect($result->throttled)->toBeTrue()
        ->and($result->successful)->toBeFalse()
        ->and($result->retryAfter)->toBe(42);
});

it('lùi về 60 giây khi header rate limit thiếu hoặc rác', function (): void {
    Http::fake(['pixabay.com/*' => Http::response('nope', 429)]);

    expect(callPixabay()->retryAfter)->toBe(60);
});

it('chặn trên retryAfter để một header dị thường không treo job hàng giờ', function (): void {
    Http::fake(['pixabay.com/*' => Http::response('nope', 429, [
        'X-RateLimit-Reset' => '999999',
    ])]);

    expect(callPixabay()->retryAfter)->toBe(300);
});

it('trả failed kèm mã khi Pixabay trả 5xx', function (): void {
    Http::fake(['pixabay.com/*' => Http::response('boom', 500)]);

    $result = callPixabay();

    expect($result->successful)->toBeFalse()
        ->and($result->throttled)->toBeFalse()
        ->and($result->reason)->toBe('http_500');
});

it('trả failed khi thân phản hồi không đúng hình dạng', function (): void {
    Http::fake(['pixabay.com/*' => Http::response(['khong' => 'co hits'])]);

    expect(callPixabay()->reason)->toBe('bad_json');
});

it('không ném khi mạng hỏng', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $result = callPixabay();

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe('transport');
});

it('không gọi mạng khi thiếu key', function (): void {
    // Thiếu key là trạng thái cấu hình đã biết, không phải sự cố. Dev chưa xin
    // key vẫn phải chạy được toàn bộ phần còn lại.
    config(['services.pixabay.key' => null]);
    Http::fake();

    expect(callPixabay()->reason)->toBe('missing_key');

    Http::assertNothingSent();
});

it('cắt truy vấn dài hơn trần 100 ký tự của Pixabay', function (): void {
    Http::fake(['pixabay.com/*' => Http::response(pixabayResponse())]);

    callPixabay(str_repeat('a', 150));

    Http::assertSent(fn ($request): bool => mb_strlen((string) $request['q']) === 100);
});
