<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{status: string, db: string, definitions_vi: int|null} $resource
 */
final class HealthResource extends JsonResource
{
    /**
     * `definitions_vi` là số dòng từ điển đã có nghĩa tiếng Việt. `null` nghĩa
     * là không đọc được bảng; 0 là tín hiệu deploy đã chạy migration nhưng chưa
     * chạy `cvdict:import`.
     *
     * @return array{status: string, db: string, definitions_vi: int|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource['status'],
            'db' => $this->resource['db'],
            'definitions_vi' => $this->resource['definitions_vi'],
        ];
    }
}
