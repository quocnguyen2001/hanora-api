<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReviewLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một lượt trả lời trong phiên.
 *
 * Dùng chung cho `POST .../finish` và `GET /reviews/sessions/{id}`. Trước đây
 * hai endpoint đó trả hai hình dạng với hai bộ lọc khác nhau (`finish` lọc sẵn
 * lượt sai, detail trả tất cả), nên cùng một phiên cho ra hai con số "từ sai"
 * trên hai màn. Một resource, một bộ lọc, app tự lọc phần nó cần.
 *
 * Lượt `is_retry` CÓ trong danh sách — đây là màn "xem lại tôi đã làm gì".
 * Cờ trả kèm để app hiển thị nhạt hơn; việc loại chúng khỏi số liệu tổng hợp
 * là chuyện của bên đếm, không phải của bên hiển thị.
 *
 * @property-read ReviewLog $resource
 */
final class ReviewAnswerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $word = $this->resource->userWord->word;

        return [
            'id' => $this->resource->id,
            'user_word_id' => $this->resource->user_word_id,
            'mode' => $this->resource->mode,
            'is_correct' => $this->resource->is_correct,
            'is_retry' => $this->resource->is_retry,
            'answer_raw' => $this->resource->answer_raw,
            'answered_at' => $this->resource->answered_at->toIso8601String(),
            'word' => [
                'id' => $word->id,
                'simplified' => $word->simplified,
                'pinyin' => $word->pinyin,
                'han_viet' => $word->han_viet,
            ],
        ];
    }
}
