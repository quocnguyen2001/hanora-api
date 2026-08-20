<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\HealthResource;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthController
{
    /**
     * Trạng thái tiến trình + kết nối database.
     *
     * Trả 200 kể cả khi DB hỏng: consumer đọc trường `db` để phân biệt,
     * còn 503 sẽ khiến load balancer rút node ra trong lúc ta cần chính
     * endpoint này để chẩn đoán.
     */
    public function __invoke(): HealthResource
    {
        return new HealthResource([
            'status' => 'ok',
            'db' => $this->databaseStatus(),
        ]);
    }

    private function databaseStatus(): string
    {
        try {
            DB::connection()->getPdo();

            return 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }
}
