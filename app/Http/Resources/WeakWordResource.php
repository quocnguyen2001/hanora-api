<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UserWord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một từ trong danh sách "hay sai".
 *
 * `wrong_count` và `accuracy` đọc từ alias mà `WeakWordQuery` đã `selectRaw` —
 * không tính lại ở đây, và KHÔNG có cột nào trong `user_words` chứa chúng: số
 * lần sai đúng bằng `review_count - correct_count` theo định nghĩa, nên thêm
 * cột là tạo nguồn sự thật thứ hai cho một con số đã có.
 *
 * @property-read UserWord $resource
 */
final class WeakWordResource extends JsonResource
{
    /**
     * @param  array<int, string|null>  $lastWrongAt  map `user_word_id` → mốc sai gần nhất
     */
    public function __construct(UserWord $resource, private readonly array $lastWrongAt = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_word_id' => $this->resource->id,
            'status' => $this->resource->status,
            'review_count' => $this->resource->review_count,
            'correct_count' => $this->resource->correct_count,
            'wrong_count' => (int) $this->resource->getAttribute('wrong_count'),
            'accuracy' => (int) $this->resource->getAttribute('accuracy'),
            'last_wrong_at' => $this->lastWrongAt[$this->resource->id] ?? null,
            'next_review_at' => $this->resource->next_review_at?->toIso8601String(),
            'word' => new WordSearchResultResource($this->resource->word),
        ];
    }
}
