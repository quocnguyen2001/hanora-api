<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Search;

use App\Models\DictionaryWord;
use App\Services\Dictionary\WordSearchService;

/**
 * Trộn kết quả AI lên trước kết quả SQL.
 *
 * **Trộn, không thay thế.** AI trả tối đa 10 từ và chỉ nhìn thấy truy vấn, không
 * nhìn thấy corpus; thay hẳn kết quả SQL bằng danh sách đó sẽ vứt mất recall ở
 * những truy vấn mà SQL vốn tìm đúng một phần — `học sinh` ra 317 dòng trong đó
 * có 学生会, 学生证 là thứ AI không nghĩ tới.
 */
final class ResultMerger
{
    /**
     * @param  list<int>  $aiIds  id theo ĐÚNG thứ tự AI xếp hạng
     * @param  list<DictionaryWord>  $sqlWords
     * @return list<DictionaryWord>
     */
    public function merge(array $aiIds, array $sqlWords): array
    {
        if ($aiIds === []) {
            return $sqlWords;
        }

        /*
         * `whereIn` KHÔNG giữ thứ tự, và thứ tự đó chính là toàn bộ đóng góp của
         * lớp AI so với SQL. Nạp một lượt rồi sắp lại theo mảng id gốc.
         */
        $loaded = DictionaryWord::query()
            ->whereIn('id', $aiIds)
            ->get()
            ->keyBy('id');

        $merged = [];
        $seen = [];

        foreach ($aiIds as $id) {
            $word = $loaded->get($id);

            if ($word instanceof DictionaryWord) {
                $merged[] = $word;
                $seen[$id] = true;
            }
        }

        foreach ($sqlWords as $word) {
            if (! isset($seen[$word->id])) {
                $merged[] = $word;
                $seen[$word->id] = true;
            }
        }

        /*
         * Cắt về đúng một trang. Không cắt thì một truy vấn yếu trả 20 dòng SQL
         * cộng 10 dòng AI thành 30, phá hợp đồng `per_page` mà FE đang dựa vào.
         */
        return array_slice($merged, 0, WordSearchService::PER_PAGE);
    }
}
