<?php

declare(strict_types=1);

namespace App\Services\Illustration;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tìm ảnh trên Pixabay.
 *
 * **Class này KHÔNG BAO GIỜ ném exception.** Mạng hỏng, 4xx, 5xx, JSON rác —
 * tất cả quy về `PixabayResult`. Cùng hợp đồng mà `GeminiClient` và
 * `HandwritingRecognizer` giữ, và vì cùng một lý do: lớp ảnh chết thì người học
 * mất một hình minh hoạ, không mất chức năng tra từ.
 *
 * Toàn bộ phụ thuộc vào hình dạng request của Pixabay nằm gọn ở đây.
 */
final class PixabayClient
{
    private const ENDPOINT = 'https://pixabay.com/api/';

    /** Pixabay từ chối `q` dài hơn 100 ký tự. */
    private const MAX_QUERY_LENGTH = 100;

    /**
     * @param  string  $lang  Mã ngôn ngữ Pixabay (`zh`, `en`, ...) — quyết định
     *                        tag trả về thuộc ngôn ngữ nào, và đó là thứ cổng
     *                        chặn đối chiếu.
     */
    public function search(string $query, string $lang): PixabayResult
    {
        $key = config('services.pixabay.key');

        /*
         * Thiếu key là trạng thái HỢP LỆ, không phải lỗi cấu hình cần báo động:
         * dev clone repo về mà chưa xin key vẫn phải chạy được toàn bộ phần còn
         * lại. Trả `failed` để caller ghi nhận rồi đi tiếp.
         */
        if (! is_string($key) || $key === '') {
            return PixabayResult::failed('missing_key');
        }

        $query = mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH);

        if ($query === '') {
            return PixabayResult::failed('empty_query');
        }

        try {
            $response = Http::timeout((int) config('services.pixabay.timeout', 8))
                ->get(self::ENDPOINT, [
                    'key' => $key,
                    'q' => $query,
                    'lang' => $lang,
                    // Chỉ ảnh chụp: vector và illustration cho ra kết quả lộn
                    // xộn hơn hẳn ở truy vấn tiếng Trung.
                    'image_type' => 'photo',
                    'safesearch' => 'true',
                    'per_page' => max(3, (int) config('services.pixabay.sample_size', 5)),
                    'order' => 'popular',
                ]);
        } catch (Throwable $e) {
            /*
             * KHÔNG log `$e->getMessage()` nguyên văn. Pixabay nhận API key qua
             * QUERY STRING, nên message của client HTTP thường chứa URL đầy đủ
             * kèm key. Chỉ log tên class.
             */
            Log::warning('pixabay: request thất bại', ['exception' => $e::class]);

            return PixabayResult::failed('transport');
        }

        /*
         * 429 KHÔNG phải lỗi của từ đang xử lý — nó là tín hiệu nhịp độ. Tách
         * riêng để job `release()` lại vào hàng đợi mà không tăng `attempts`.
         */
        if ($response->status() === 429) {
            return PixabayResult::throttled(
                self::retryAfterSeconds($response->header('X-RateLimit-Reset'))
            );
        }

        if (! $response->successful()) {
            Log::warning('pixabay: phản hồi lỗi', ['status' => $response->status()]);

            return PixabayResult::failed('http_'.$response->status());
        }

        $body = $response->json();

        if (! is_array($body) || ! isset($body['hits']) || ! is_array($body['hits'])) {
            Log::warning('pixabay: phản hồi không phải JSON đúng hình dạng');

            return PixabayResult::failed('bad_json');
        }

        /** @var list<array<string, mixed>> $hits */
        $hits = array_values(array_filter($body['hits'], is_array(...)));

        return PixabayResult::ok((int) ($body['totalHits'] ?? 0), $hits);
    }

    /**
     * `X-RateLimit-Reset` là số giây còn lại của cửa sổ hiện tại.
     *
     * Thiếu hoặc rác thì lùi về 60 — đúng độ dài một cửa sổ rate limit của
     * Pixabay. Chặn trên 300 để một header dị thường không treo job hàng giờ.
     */
    private static function retryAfterSeconds(?string $header): int
    {
        $seconds = is_numeric($header) ? (int) $header : 60;

        return max(1, min($seconds, 300));
    }
}
