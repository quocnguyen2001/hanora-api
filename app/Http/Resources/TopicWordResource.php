<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DictionaryWord;
use App\Services\Topic\TopicGloss;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một từ trong bộ từ của chủ đề.
 *
 * KHÔNG có `saved`/`skipped`, cùng quyết định bảo mật với
 * `WordSearchResultResource`: response này cache được và dùng chung cho mọi
 * user. App suy trạng thái từ `/vocabulary/ids` và `/topics/skips`.
 *
 * @property-read DictionaryWord $resource
 */
final class TopicWordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'simplified' => $this->resource->simplified,
            'traditional' => $this->resource->traditional,
            'pinyin' => $this->resource->pinyin,
            'han_viet' => $this->resource->han_viet,
            /*
             * CHỈ nghĩa đầu tiên dùng được, cả hai ngôn ngữ.
             *
             * Thẻ học hiện đúng một dòng nghĩa, nên trả cả mảng là băng thông
             * cho dữ liệu không ai nhìn — đo được: 80 từ với mảng đầy đủ là
             * 24-32KB, không phải 16KB như ước tính ban đầu.
             *
             * `TopicGloss` là cùng luật mà `TopicWordResolver` dùng để GÁC. Hai
             * đường khác nhau sẽ cho ra một bộ từ qua cổng nhờ nghĩa sạch rồi
             * hiện nghĩa bẩn trên thẻ.
             */
            'definition_vi' => TopicGloss::teachingFrom($this->resource->definitions_vi),
            // Dòng tiếng Anh ở lại làm chốt đối chiếu: nghĩa Việt CVDICT do máy
            // dịch và tác giả thừa nhận còn sót lỗi.
            'definition_en' => $this->resource->definitions_en[0] ?? null,
            'hsk_level' => $this->resource->hsk_level,
            // Thứ tự trong chủ đề. Màn học bốc ngẫu nhiên trong LÁT CẮT đầu.
            'rank' => (int) ($this->resource->rank ?? 0),
        ];
    }
}
