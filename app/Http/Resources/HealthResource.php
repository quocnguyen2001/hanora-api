<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{status: string, db: string, vi_lexicon: int|null} $resource
 */
final class HealthResource extends JsonResource
{
    /**
     * `vi_lexicon` là số mục từ điển cầu nối Việt-Anh; `null` nghĩa là không đọc
     * được bảng. 0 là tín hiệu deploy đã chạy migration nhưng chưa import.
     *
     * @return array{status: string, db: string, vi_lexicon: int|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource['status'],
            'db' => $this->resource['db'],
            'vi_lexicon' => $this->resource['vi_lexicon'],
        ];
    }
}
