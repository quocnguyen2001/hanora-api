<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UserWord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một mục trong kho từ, kèm dữ liệu từ điển đã nhúng để FE không phải gọi thêm.
 *
 * @property-read UserWord $resource
 */
final class UserWordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'status' => $this->resource->status,
            'review_count' => $this->resource->review_count,
            'correct_count' => $this->resource->correct_count,
            'next_review_at' => $this->resource->next_review_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'word' => new WordSearchResultResource($this->resource->word),
        ];
    }
}
