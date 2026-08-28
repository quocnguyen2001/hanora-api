<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sinh nội dung có cấu trúc bằng Gemini.
 *
 * **Class này KHÔNG BAO GIỜ ném exception.** Mạng hỏng, 4xx, 5xx, JSON rác —
 * tất cả quy về `GeminiResult`. Đây là cùng hợp đồng mà `HandwritingRecognizer`
 * giữ, và vì cùng một lý do: lớp làm giàu chết thì người học mất một khối nội
 * dung, không mất chức năng tra từ. Một exception lọt ra ngoài sẽ biến việc đó
 * thành 500 trên màn chi tiết.
 *
 * Toàn bộ phụ thuộc vào hình dạng request của Google nằm gọn ở đây. Google đã
 * đổi một lần rồi — từ `:generateContent` + `generationConfig` sang
 * `/interactions` + `response_format` — nên khi họ đổi tiếp, sửa đúng một file.
 */
final class GeminiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    /**
     * Sinh một payload JSON theo `$schema`.
     *
     * @param  array<string, mixed>  $schema  JSON Schema mô tả hình dạng đầu ra
     * @param  int|null  $timeout  Ghi đè trần thời gian. Lớp search cần trần
     *                             NGẮN hơn hẳn lớp làm giàu: ở đó một job nền
     *                             chờ 30 giây không ai biết, còn ở đây là một
     *                             request đang giữ worker PHP-FPM.
     */
    public function generate(string $prompt, array $schema, ?int $timeout = null): GeminiResult
    {
        $key = config('services.gemini.key');

        /*
         * Thiếu key là trạng thái HỢP LỆ, không phải lỗi cấu hình cần báo động:
         * dev clone repo về mà chưa xin key vẫn phải chạy được toàn bộ phần còn
         * lại. Trả `failed` để caller ghi nhận rồi đi tiếp.
         */
        if (! is_string($key) || $key === '') {
            return GeminiResult::failed('missing_key');
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->timeout($timeout ?? (int) config('services.gemini.timeout', 30))
                ->asJson()
                ->post(self::ENDPOINT, [
                    'model' => (string) config('services.gemini.model'),
                    'input' => $prompt,
                    'response_format' => [
                        'type' => 'text',
                        'mime_type' => 'application/json',
                        'schema' => $schema,
                    ],
                ]);
        } catch (Throwable $e) {
            // KHÔNG log `$e->getMessage()` nguyên văn: message của client HTTP
            // có thể chứa URL đầy đủ, và caller khác có thể nhét key vào query.
            Log::warning('gemini: request thất bại', ['exception' => $e::class]);

            return GeminiResult::failed('transport');
        }

        /*
         * 429 KHÔNG phải lỗi của từ đang xử lý — nó là tín hiệu nhịp độ. Tách
         * riêng để job `release()` lại vào hàng đợi mà không tăng `attempts`;
         * gộp nó vào nhánh lỗi chung sẽ khiến một đợt pre-warm chạm trần đánh
         * dấu `failed` hàng loạt từ hoàn toàn bình thường.
         */
        if ($response->status() === 429) {
            return GeminiResult::throttled(self::retryAfterSeconds($response->header('Retry-After')));
        }

        if (! $response->successful()) {
            Log::warning('gemini: phản hồi lỗi', ['status' => $response->status()]);

            return GeminiResult::failed('http_'.$response->status());
        }

        $payload = self::decodePayload($response->json());

        if ($payload === null) {
            Log::warning('gemini: phản hồi không phải JSON đúng hình dạng');

            return GeminiResult::failed('malformed');
        }

        return GeminiResult::ok($payload, self::usage($response->json()));
    }

    /**
     * Bóc JSON của model ra khỏi lớp bọc của API.
     *
     * Hình dạng thật, xác minh bằng lời gọi trực tiếp 2026-08-28:
     *
     *   steps[0] = { type: "thought",      signature: ... }
     *   steps[1] = { type: "model_output", content: [ { type: "text", text: "{...}" } ] }
     *
     * **Phải QUÉT chứ không được lấy `steps[0]`.** Bước `thought` có mặt hay
     * không là tùy model và tùy lượt; neo vào chỉ số sẽ chạy đúng hôm nay rồi
     * trả `malformed` vào một ngày model quyết định không suy nghĩ.
     *
     * `response_format.mime_type = application/json` chỉ ràng buộc NỘI DUNG của
     * trường `text`, nên vẫn phải decode một lần nữa.
     *
     * @return array<string, mixed>|null
     */
    private static function decodePayload(mixed $body): ?array
    {
        if (! is_array($body)) {
            return null;
        }

        foreach (self::outputTexts($body) as $text) {
            $decoded = json_decode($text, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Mọi đoạn văn bản model sinh ra, theo thứ tự xuất hiện.
     *
     * @param  array<mixed>  $body
     * @return list<string>
     */
    private static function outputTexts(array $body): array
    {
        $steps = $body['steps'] ?? null;

        if (! is_array($steps)) {
            return [];
        }

        $texts = [];

        foreach ($steps as $step) {
            if (! is_array($step) || ($step['type'] ?? null) !== 'model_output') {
                continue;
            }

            foreach ($step['content'] ?? [] as $part) {
                if (is_array($part) && is_string($part['text'] ?? null) && $part['text'] !== '') {
                    $texts[] = $part['text'];
                }
            }
        }

        return $texts;
    }

    /**
     * Số token thật của lượt gọi. Đây là thứ duy nhất quy ra tiền được — mọi
     * ước lượng từ độ dài prompt đều lệch vì tokenizer và vì `thought` token.
     *
     * @return array{input: int, output: int}
     */
    private static function usage(mixed $body): array
    {
        $usage = is_array($body) ? ($body['usage'] ?? []) : [];

        return [
            'input' => is_array($usage) ? (int) ($usage['total_input_tokens'] ?? 0) : 0,
            'output' => is_array($usage) ? (int) ($usage['total_output_tokens'] ?? 0) : 0,
        ];
    }

    /**
     * `Retry-After` là giây, hoặc một mốc HTTP-date. Không đọc được thì lùi 60
     * giây — đủ lâu để không quay lại đúng lúc vẫn còn bị chặn.
     */
    private static function retryAfterSeconds(?string $header): int
    {
        if ($header === null || $header === '') {
            return 60;
        }

        if (ctype_digit(trim($header))) {
            return max(1, min(3600, (int) trim($header)));
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return 60;
        }

        return max(1, min(3600, $timestamp - time()));
    }
}
