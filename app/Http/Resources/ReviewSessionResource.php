<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReviewSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Hình dạng một phiên ôn trong JSON — một chỗ duy nhất quyết định nó.
 *
 * @property-read ReviewSession $resource
 */
final class ReviewSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'mode' => $this->resource->mode,
            'source' => $this->resource->source,
            'planned_count' => $this->resource->planned_count,
            'answered_count' => $this->resource->answered_count,
            'correct_count' => $this->resource->correct_count,
            'score' => $this->resource->score,
            'grade' => $this->resource->grade,
            // Tính, không lưu — xem `ReviewSession::durationSeconds()` về chiều
            // diff của Carbon 3.
            'duration_seconds' => $this->resource->durationSeconds(),
            'started_at' => $this->resource->started_at->toIso8601String(),
            'finished_at' => $this->resource->finished_at?->toIso8601String(),
        ];
    }
}
