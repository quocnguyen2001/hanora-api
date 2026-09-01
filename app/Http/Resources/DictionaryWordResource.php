<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DictionaryWord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chi tiết một từ. Cũng KHÔNG có trường theo user — xem WordSearchResultResource.
 *
 * @property-read DictionaryWord $resource
 */
final class DictionaryWordResource extends JsonResource
{
    /**
     * @param  list<array{char: string, pinyin: string, han_viet: string|null}>  $characters
     */
    public function __construct(DictionaryWord $resource, private readonly array $characters = [])
    {
        parent::__construct($resource);
    }

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
            'definitions_en' => $this->resource->definitions_en,
            /*
             * Nghĩa tiếng Việt từ CVDICT. `null` là trạng thái HỢP LỆ cho ~7%
             * mục không có trong nguồn — FE ẩn hẳn phần nghĩa Việt cho chúng,
             * không hiện khung trống, cùng quy ước mà `han_viet: null` dùng.
             *
             * `definitions_en` KHÔNG bị thay thế và không bao giờ được thay
             * thế: nghĩa tiếng Việt dịch bằng AI có người rà, còn sót lỗi, và
             * dòng tiếng Anh là cơ chế đối chiếu duy nhất người học có.
             */
            'definitions_vi' => $this->resource->definitions_vi,
            /*
             * Lượng từ, tách khỏi `definitions_en` lúc import.
             *
             * Trước đó CC-CEDICT để chúng NGAY TRONG phần nghĩa dưới dạng
             * `CL:家[jia1],個|个[ge4]`, và chuỗi đó hiện nguyên dạng mã cho người
             * dùng. Tách ra vừa cho FE một mục "Lượng từ" đọc được, vừa bịt chỗ rò.
             *
             * `[]` chứ không `null` ở tầng này có chủ đích: `null` trong cột nói
             * "từ này không có lượng từ", nhưng FE không cần phân biệt nó với
             * danh sách rỗng — cả hai đều render thành không có gì. Chuẩn hoá ở
             * đây để app khỏi mang hai nhánh cho cùng một ý.
             */
            'measure_words' => $this->resource->measure_words ?? [],
            'hsk_level' => $this->resource->hsk_level,
            'characters' => $this->characters,
            /*
             * Câu ví dụ Tatoeba (P13). Mảng RỖNG là trạng thái hợp lệ — chỉ
             * ~85% từ trong tập ưu tiên có câu, và FE ẩn hẳn section đó (D6).
             *
             * `contributor` và `license` đi kèm từng câu vì Tatoeba là CC BY:
             * nghĩa vụ là ghi công tác giả của CHÍNH câu đó, và license được
             * xuất bản theo từng câu.
             */
            'examples' => $this->resource->relationLoaded('examples')
                ? $this->resource->examples->map(fn ($example): array => [
                    'id' => $example->id,
                    'sentence_zh' => $example->sentence_zh,
                    'translation_en' => $example->translation_en,
                    'contributor' => $example->contributor,
                    'license' => $example->license,
                ])->all()
                : [],
        ];
    }
}
