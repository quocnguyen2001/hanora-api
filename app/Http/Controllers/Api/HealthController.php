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
            'vi_lexicon' => $this->viLexiconCount(),
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
     * Số mục từ điển cầu nối Việt-Anh.
     *
     * Ở đây vì đó là cách duy nhất phát hiện được TỪ XA rằng deploy đã chạy
     * migration nhưng quên `vi-lexicon:import`. Thiếu dữ liệu thì tìm kiếm bằng
     * nghĩa tiếng Việt hỏng IM LẶNG — không lỗi, không log, kết quả rỗng trông
     * giống hệt một truy vấn không khớp hợp lệ.
     *
     * `null` khi không đọc được, để phân biệt với 0 mục thật.
     *
     * Cache 60 giây: `count(*)` không điều kiện trên bảng này là seq scan
     * (đo được 7,1ms trên 54k dòng, không có đường index-only), còn `/api/health`
     * là route CÔNG KHAI duy nhất. Một endpoint chẩn đoán không nên mang theo
     * truy vấn lớn dần theo dữ liệu. 60 giây đủ nhanh cho việc nó phục vụ —
     * phát hiện deploy quên import.
     */
    private function viLexiconCount(): ?int
    {
        try {
            return Cache::remember('health:vi_lexicon_count', 60, fn (): int => DB::table('vi_en_lexicon')->count());
        } catch (Throwable) {
            return null;
        }
    }
}
