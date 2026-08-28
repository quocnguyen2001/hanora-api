<?php

declare(strict_types=1);

use App\Services\Gemini\GeminiClient;
use App\Services\Gemini\GeminiResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Hợp đồng của class này là "KHÔNG BAO GIỜ ném exception".
 *
 * Mỗi ca dưới đây là một cách Gemini có thể hỏng trong thực tế. Nếu một trong
 * số chúng ném ra ngoài, màn chi tiết từ sẽ trả 500 vì một tính năng phụ —
 * đúng thứ mà khuôn suy giảm êm của `HandwritingRecognizer` sinh ra để tránh.
 */
beforeEach(function (): void {
    config([
        'services.gemini.key' => 'test-key',
        'services.gemini.model' => 'gemini-2.5-flash-lite',
        'services.gemini.timeout' => 5,
    ]);
});

function callGemini(): GeminiResult
{
    return app(GeminiClient::class)->generate('prompt thử', ['type' => 'object']);
}

/**
 * Hình dạng response thật của Interactions API, chép từ một lời gọi trực tiếp
 * ngày 2026-08-28. Bước `thought` đứng TRƯỚC `model_output` là chi tiết quan
 * trọng nhất ở đây — nó là lý do client phải quét `steps` thay vì lấy phần tử
 * đầu.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function geminiResponse(array $payload, int $input = 8, int $output = 12): array
{
    return [
        'status' => 'completed',
        'usage' => ['total_input_tokens' => $input, 'total_output_tokens' => $output],
        'steps' => [
            ['type' => 'thought', 'signature' => 'abc'],
            ['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => json_encode($payload)],
            ]],
        ],
        'model' => 'gemini-3.5-flash-lite',
    ];
}

it('bóc được payload JSON nằm sau bước thought', function (): void {
    Http::fake(['*' => Http::response(geminiResponse(['senses' => [['pos' => 'động từ', 'vi' => 'yêu']]]))]);

    $result = callGemini();

    expect($result->successful)->toBeTrue()
        ->and($result->payload)->toBe(['senses' => [['pos' => 'động từ', 'vi' => 'yêu']]]);
});

it('đọc số token thật từ usage', function (): void {
    // Ước token từ độ dài prompt luôn lệch — `thought` token không hiện ra ở
    // prompt. Đây là con số duy nhất quy ra tiền được.
    Http::fake(['*' => Http::response(geminiResponse(['senses' => []], input: 431, output: 1207))]);

    expect(callGemini()->usage)->toBe(['input' => 431, 'output' => 1207]);
});

it('bỏ qua step không phải model_output', function (): void {
    Http::fake(['*' => Http::response([
        'steps' => [
            ['type' => 'thought', 'signature' => 'chỉ có suy nghĩ, không có đầu ra'],
        ],
    ])]);

    expect(callGemini()->reason)->toBe('malformed');
});

it('gửi key qua header x-goog-api-key, không nhét vào URL', function (): void {
    Http::fake(['*' => Http::response(geminiResponse(['senses' => []]))]);

    callGemini();

    Http::assertSent(function ($request): bool {
        // Key trong query string sẽ rơi vào log của mọi proxy trên đường đi.
        return $request->hasHeader('x-goog-api-key', 'test-key')
            && ! str_contains($request->url(), 'test-key');
    });
});

it('trả failed thay vì ném khi mạng hỏng', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $result = callGemini();

    expect($result->successful)->toBeFalse()
        ->and($result->throttled)->toBeFalse()
        ->and($result->reason)->toBe('transport');
});

it('trả failed thay vì ném khi API trả 500', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    $result = callGemini();

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe('http_500');
});

it('trả failed khi model trả về chuỗi không phải JSON', function (): void {
    Http::fake(['*' => Http::response([
        'steps' => [
            ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'xin chào, tôi không phải JSON']]],
        ],
    ])]);

    $result = callGemini();

    expect($result->successful)->toBeFalse()
        ->and($result->reason)->toBe('malformed');
});

it('trả failed khi thiếu key, không gọi mạng', function (): void {
    config(['services.gemini.key' => '']);
    Http::fake();

    $result = callGemini();

    expect($result->reason)->toBe('missing_key');
    Http::assertNothingSent();
});

describe('429 tách khỏi lỗi thật', function (): void {
    it('đọc Retry-After dạng số giây', function (): void {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '42'])]);

        $result = callGemini();

        expect($result->throttled)->toBeTrue()
            ->and($result->successful)->toBeFalse()
            ->and($result->retryAfter)->toBe(42);
    });

    it('lùi 60 giây khi không có Retry-After', function (): void {
        Http::fake(['*' => Http::response('', 429)]);

        expect(callGemini()->retryAfter)->toBe(60);
    });

    it('chặn trên Retry-After ở 1 giờ', function (): void {
        // Một header hỏng bảo chờ 30 ngày sẽ treo job đó vĩnh viễn.
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '9999999'])]);

        expect(callGemini()->retryAfter)->toBe(3600);
    });
});
