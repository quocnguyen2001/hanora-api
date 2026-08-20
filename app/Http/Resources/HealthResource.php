<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{status: string, db: string} $resource
 */
final class HealthResource extends JsonResource
{
    /**
     * @return array{status: string, db: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource['status'],
            'db' => $this->resource['db'],
        ];
    }
}
