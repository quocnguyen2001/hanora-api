<?php

declare(strict_types=1);

namespace App\Services\Illustration;

/**
 * Một ảnh đã qua cổng chặn, sẵn sàng lưu.
 *
 * `author` và `pageUrl` KHÔNG phải siêu dữ liệu tuỳ chọn: ToS Pixabay đòi
 * "show your users where the images are from, whenever search results are
 * displayed". Thiếu chúng là dùng ảnh sai điều khoản.
 */
final readonly class IllustrationCandidate
{
    public function __construct(
        public int $sourceId,
        public string $imageUrl,
        public string $previewUrl,
        public string $pageUrl,
        public ?string $author,
        public ?string $authorUrl,
        public ?int $width,
        public ?int $height,
        public string $matchedQuery,
    ) {}
}
