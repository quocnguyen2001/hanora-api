<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use App\Models\DictionaryWord;

/**
 * Ghép âm Hán-Việt cho cả một mục từ điển.
 *
 * Nguyên tắc chi phối mọi quyết định ở đây: **không hiển thị âm sai còn tốt hơn
 * hiển thị bừa.** Người học sẽ nhớ cái sai, và với một app từ vựng thì đó là
 * thiệt hại thẳng vào chính giá trị sản phẩm. Nên bất kỳ ký tự nào không chắc
 * chắn cũng làm cả từ chuyển sang `ambiguous`/`missing` và không hiển thị.
 */
final class HanVietComposer
{
    public function __construct(
        private readonly HanVietReadingTable $table,
        private readonly PinyinNormalizer $pinyin,
    ) {}

    /**
     * @return array{han_viet: string|null, han_viet_plain: string|null, han_viet_status: string}
     */
    public function compose(string $traditional, string $pinyinNumbered): array
    {
        $characters = mb_str_split($traditional);
        $syllables = $this->alignSyllables($pinyinNumbered, count($characters));

        $readings = [];

        foreach ($characters as $index => $character) {
            $reading = $this->table->lookup($character, $syllables[$index] ?? null);

            if ($reading === false) {
                return $this->blank(DictionaryWord::STATUS_MISSING);
            }

            if ($reading === null) {
                return $this->blank(DictionaryWord::STATUS_AMBIGUOUS);
            }

            $readings[] = $reading;
        }

        if ($readings === []) {
            return $this->blank(DictionaryWord::STATUS_MISSING);
        }

        $hanViet = implode(' ', $readings);

        return [
            'han_viet' => $hanViet,
            // Giữ khoảng trắng: `hoc tap` vẫn là hai tiếng, để prefix match theo
            // tiếng còn hoạt động ở P6.
            'han_viet_plain' => $this->pinyin->stripDiacritics($hanViet),
            'han_viet_status' => DictionaryWord::STATUS_OK,
        ];
    }

    /**
     * Ghép từng âm tiết pinyin với từng ký tự.
     *
     * Khi số âm tiết không khớp số ký tự — mục có token không phải pinyin, chữ
     * số, hoặc r hóa — thì bỏ hẳn phần pinyin thay vì ghép lệch. Ghép lệch sẽ
     * tra 行 bằng âm tiết của ký tự bên cạnh và cho ra âm sai một cách tự tin.
     *
     * @return list<string|null>
     */
    private function alignSyllables(string $pinyinNumbered, int $characterCount): array
    {
        $syllables = preg_split('/\s+/u', trim($pinyinNumbered), -1, PREG_SPLIT_NO_EMPTY);

        if ($syllables === false || count($syllables) !== $characterCount) {
            return array_fill(0, $characterCount, null);
        }

        return $syllables;
    }

    /**
     * @return array{han_viet: null, han_viet_plain: null, han_viet_status: string}
     */
    private function blank(string $status): array
    {
        return [
            'han_viet' => null,
            'han_viet_plain' => null,
            'han_viet_status' => $status,
        ];
    }
}
