<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\HealthResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthController
{
    /**
     * Trạng thái tiến trình + kết nối database + dữ liệu tra cứu.
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
            'definitions_vi' => $this->definitionsViCount(),
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

    /**
     * Số dòng từ điển đã có nghĩa tiếng Việt.
     *
     * Ở đây vì đó là cách duy nhất phát hiện được TỪ XA rằng deploy đã chạy
     * migration nhưng quên `cvdict:import`. Thiếu dữ liệu thì hỏng IM LẶNG —
     * không lỗi, không log: thẻ từ chỉ đơn giản là không có nghĩa tiếng Việt, và
     * tìm bằng tiếng Việt trả rỗng trông giống hệt một truy vấn không khớp hợp lệ.
     *
     * `null` khi không đọc được, để phân biệt với 0 dòng thật.
     *
     * Cache 60 giây: đây là điều kiện lọc trên 123k dòng, còn `/api/health` là
     * route CÔNG KHAI duy nhất. Một endpoint chẩn đoán không nên mang theo truy
     * vấn lớn dần theo dữ liệu.
     */
    private function definitionsViCount(): ?int
    {
        try {
            return Cache::remember(
                'health:definitions_vi_count',
                60,
                fn (): int => DB::table('dictionary_words')->whereNotNull('definitions_vi')->count()
            );
        } catch (Throwable) {
            return null;
        }
    }
}
