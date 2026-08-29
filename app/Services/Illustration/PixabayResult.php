<?php

declare(strict_types=1);

namespace App\Services\Illustration;

/**
 * Kết quả một lời gọi tìm ảnh Pixabay.
 *
 * Ba trạng thái, không phải hai — cùng lý do mà `GeminiResult` đã ghi lại:
 * `throttled` phải tách khỏi `failed` vì hai thứ đó xử lý ngược nhau. 429 phải
 * `release()` job lại vào hàng đợi mà KHÔNG tăng `attempts`; lỗi thật thì phải
 * tăng. Gộp lại thì một đợt chạm trần rate limit sẽ đánh dấu `failed` hàng loạt
 * từ hoàn toàn bình thường — và vì bản ghi cache vĩnh viễn, sai đó không tự khỏi.
 */
final readonly class PixabayResult
{
    /**
     * @param  list<array<string, mixed>>  $hits
     */
    private function __construct(
        public bool $successful,
        public bool $throttled,
        public int $totalHits,
        public array $hits,
        public ?string $reason,
        public int $retryAfter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $hits
     */
    public static function ok(int $totalHits, array $hits): self
    {
        return new self(true, false, $totalHits, $hits, null, 0);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self(false, true, 0, [], 'rate_limited', $retryAfter);
    }

    public static function failed(string $reason): self
    {
        return new self(false, false, 0, [], $reason, 0);
    }
}
