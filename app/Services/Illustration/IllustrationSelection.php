<?php

declare(strict_types=1);

namespace App\Services\Illustration;

/**
 * Kết quả một lượt chọn ảnh.
 *
 * BỐN kết cục, và việc tách chúng ra là điểm cốt lõi của lớp này:
 *
 * - `found`     — có ảnh dùng được.
 * - `none`      — cổng đóng. Đây là kết quả ĐÚNG và VĨNH VIỄN, không phải lỗi:
 *                 hư từ và từ trừu tượng không nên có ảnh minh hoạ.
 * - `throttled` — chạm trần nhịp độ. Job `release()`, `attempts` GIỮ NGUYÊN.
 * - `failed`    — API hỏng thật. Job tăng `attempts`.
 *
 * Trộn `none` với `failed` là biến một sự cố mạng tạm thời thành "từ này vĩnh
 * viễn không có ảnh" — và vì bản ghi cache vĩnh viễn, sai đó không tự khỏi.
 */
final readonly class IllustrationSelection
{
    private function __construct(
        public ?IllustrationCandidate $candidate,
        public bool $gateClosed,
        public bool $throttled,
        public ?string $reason,
        public int $retryAfter,
    ) {}

    public static function found(IllustrationCandidate $candidate): self
    {
        return new self($candidate, false, false, null, 0);
    }

    public static function none(): self
    {
        return new self(null, true, false, null, 0);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self(null, false, true, 'rate_limited', $retryAfter);
    }

    public static function failed(string $reason): self
    {
        return new self(null, false, false, $reason, 0);
    }
}
