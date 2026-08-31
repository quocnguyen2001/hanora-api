<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Topic;
use App\Services\Topic\TopicCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một chủ đề trên lưới, kèm tiến độ của người đang đăng nhập.
 *
 * @property-read Topic $resource
 */
final class TopicResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /*
         * `name` và `emoji` đọc từ `TopicCatalog`, KHÔNG từ bảng.
         *
         * Bảng `topics` là bản chiếu do `topics:import` đồng bộ. Đọc từ bảng thì
         * đổi tên chủ đề trong code mà quên chạy import sẽ khiến app hiện tên cũ
         * trong khi code nói tên mới — không lỗi, không test nào đỏ. Hằng là
         * nguồn sự thật, nên hằng là thứ được hiển thị.
         */
        $catalog = TopicCatalog::find($this->resource->slug);

        return [
            'slug' => $this->resource->slug,
            'name' => $catalog['name'] ?? $this->resource->name,
            'emoji' => $catalog['emoji'] ?? $this->resource->emoji,
            'word_count' => (int) ($this->resource->word_count ?? 0),
            /*
             * MỘT con số cho tiến độ, không phải hai để client tự cộng.
             *
             * Một từ có thể vừa được lưu vừa bị bỏ qua (bỏ qua ở chủ đề A rồi
             * lưu từ màn Tìm kiếm). Trả `learned` và `skipped` riêng rồi để
             * client cộng lại sẽ đếm từ đó hai lần, và thẻ hiện `80/78` với
             * thanh tiến độ tràn.
             */
            'processed_count' => (int) ($this->resource->processed_count ?? 0),
            // Vẫn trả riêng để màn hình nói được "đã lưu bao nhiêu", nhưng KHÔNG
            // dùng cho tiến độ hay điều kiện hoàn thành.
            'learned_count' => (int) ($this->resource->learned_count ?? 0),
            /*
             * Chủ đề tự tạo có vòng đời; chủ đề gốc luôn `ready`.
             *
             * App poll `GET /topics` khi còn chủ đề `generating`, và hiện nút
             * xoá cho `failed` — không có `status` thì một job chết trông giống
             * hệt một chủ đề rỗng.
             */
            'status' => $this->resource->status,
            'is_custom' => $this->resource->isCustom(),
            'failed_reason' => $this->resource->failed_reason,
        ];
    }
}
