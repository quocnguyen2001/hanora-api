<?php

declare(strict_types=1);

namespace App\Services\Gemini;

/**
 * Kết quả một lời gọi Gemini.
 *
 * Ba trạng thái, không phải hai. `throttled` tách khỏi `failed` vì hai thứ đó
 * cần xử lý ngược nhau: 429 phải `release()` job lại vào hàng đợi mà KHÔNG tăng
 * `attempts`, còn lỗi thật thì phải tăng. Gộp chúng lại thì một đợt pre-warm
 * chạm trần rate limit sẽ đánh dấu `failed` hàng loạt từ hoàn toàn bình thường
 * — và vì bản ghi được cache vĩnh viễn, sai đó không tự khỏi.
 */
final readonly class GeminiResult
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array{input: int, output: int}  $usage
     */
    private function __construct(
        public bool $successful,
        public bool $throttled,
        public ?array $payload,
        public ?string $reason,
        public int $retryAfter,
        public array $usage,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{input: int, output: int}  $usage
     */
    public static function ok(array $payload, array $usage = ['input' => 0, 'output' => 0]): self
    {
        return new self(true, false, $payload, null, 0, $usage);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self(false, true, null, 'rate_limited', $retryAfter, ['input' => 0, 'output' => 0]);
    }

    public static function failed(string $reason): self
    {
        return new self(false, false, null, $reason, 0, ['input' => 0, 'output' => 0]);
    }
}
