<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DictionaryWordIllustration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ảnh minh hoạ của một mục từ.
 *
 * `author`, `author_url` và `page_url` là BẮT BUỘC, không phải siêu dữ liệu
 * trang trí: ToS Pixabay đòi "show your users where the images are from,
 * whenever search results are displayed". Bỏ chúng đi là dùng ảnh sai điều khoản.
 *
 * KHÔNG có trường nào theo user — response này cache `public`, cùng quy ước bảo
 * mật mà `WordSearchResultResource` ghi lại (red team C2).
 *
 * @property-read DictionaryWordIllustration $resource
 */
final class WordIllustrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * FE chỉ đọc `url`. Đây là điểm bản lề của quyết định hotlink: đổi
             * sang tự host ảnh về sau chỉ cần sửa đúng dòng này cộng với job —
             * FE không đổi một dòng, và không phải migrate dữ liệu vì
             * `source_id`/`page_url` đã đủ để tải lại ảnh gốc.
             */
            'url' => $this->resource->image_url,

            // Đường lui khi bản `_640` suy ra được lại hỏng; bản `_150` có
            // trong docs Pixabay nên ổn định hơn.
            'preview_url' => $this->resource->preview_url,

            'width' => $this->resource->width,
            'height' => $this->resource->height,
            'author' => $this->resource->author,
            'author_url' => $this->resource->author_url,
            'page_url' => $this->resource->page_url,
            'source' => 'pixabay',
        ];
    }
}
