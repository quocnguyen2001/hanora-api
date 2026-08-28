<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DictionaryWordEnrichment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Nội dung làm giàu do AI sinh.
 *
 * `source` và `model` là BẮT BUỘC, không phải siêu dữ liệu cho vui: nội dung ở
 * đây không có license, không có người rà, và có thể sai. Người học phải biết
 * dòng nào do máy sinh để còn đối chiếu với `definitions_en` của CC-CEDICT.
 *
 * KHÔNG có trường nào theo user — response này cache `public`, cùng quy ước bảo
 * mật mà `WordSearchResultResource` ghi lại (red team C2).
 *
 * @property-read DictionaryWordEnrichment $resource
 */
final class WordEnrichmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = $this->resource->payload ?? [];

        return [
            'senses' => $payload['senses'] ?? [],
            'examples' => $payload['examples'] ?? [],
            'characters' => $payload['characters'] ?? [],
            'related_words' => $payload['related_words'] ?? [],
            'idioms' => $payload['idioms'] ?? [],
            'usage_note' => $payload['usage_note'] ?? null,
            'source' => 'ai',
            'model' => $this->resource->model,
            'generated_at' => $this->resource->generated_at?->toIso8601String(),
        ];
    }
}
