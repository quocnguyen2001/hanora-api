<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DictionaryWord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một mục trong danh sách kết quả tìm kiếm.
 *
 * KHÔNG có `saved` và `user_word_id`, và đó là quyết định bảo mật chứ không
 * phải quên (red team C2). Response này được cache dài hạn ở cả HTTP lẫn
 * service worker; nhét trường theo user vào đây là rò dữ liệu giữa các tài
 * khoản trên máy dùng chung. FE lấy trạng thái đã lưu qua
 * `GET /api/vocabulary/ids`, endpoint `private, no-store`.
 *
 * @property-read DictionaryWord $resource
 */
final class WordSearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'simplified' => $this->resource->simplified,
            'traditional' => $this->resource->traditional,
            'pinyin' => $this->resource->pinyin,
            // `null` khi P5 không ghép được. FE ẩn hẳn dòng đó, không hiện
            // chuỗi rỗng và tuyệt đối không bịa âm.
            'han_viet' => $this->resource->han_viet,
            'definitions_en' => $this->resource->definitions_en,
            'hsk_level' => $this->resource->hsk_level,
        ];
    }
}
