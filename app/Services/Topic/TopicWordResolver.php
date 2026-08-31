<?php

declare(strict_types=1);

namespace App\Services\Topic;

use App\Models\DictionaryWord;
use Illuminate\Support\Collection;

/**
 * Phân giải một đề xuất của model về đúng MỘT mục từ điển, và áp cổng chất lượng.
 *
 * Corpus là trọng tài, không phải model — cùng khuôn mà `SearchInterpreter` đã
 * dựng cho lớp diễn giải truy vấn.
 */
final class TopicWordResolver
{
    /**
     * Phân giải theo khoá tự nhiên `(simplified, pinyin_numbered)`.
     *
     * Dùng lúc IMPORT, khi cách đọc đã được chốt và ghi vào JSON. Truy vấn nằm
     * trên unique index nên trả tối đa một dòng — không có nhánh `Ambiguous` ở
     * đây, đó là việc của `TopicGenerator`.
     */
    public function resolveExact(string $zh, string $pinyinNumbered): TopicWordResolution
    {
        $rows = DictionaryWord::query()->where('simplified', $zh)->orderBy('id')->get();

        /*
         * Khớp CHÍNH XÁC trước, kể cả hoa/thường.
         *
         * `美` có hai mục: `Mei3` (Châu Mỹ) và `mei3` (đẹp). So khớp bỏ qua
         * hoa/thường sẽ trả về mục nào có id nhỏ hơn — tức là quay về đúng luật
         * "id nhỏ nhất" mà D10 tồn tại để loại bỏ, và làm hỏng chính lựa chọn mà
         * `topics:generate` đã ghi vào JSON.
         */
        $word = $rows->first(
            fn (DictionaryWord $w): bool => NumberedPinyin::matchesExact($w->pinyin_numbered, $pinyinNumbered)
        );

        if (! $word instanceof DictionaryWord) {
            // Không khớp chính xác: chấp nhận khớp bỏ hoa/thường CHỈ KHI duy
            // nhất, để người rà gõ `hua1` thay cho `Hua1` vẫn dùng được.
            $loose = $rows->filter(
                fn (DictionaryWord $w): bool => NumberedPinyin::matches($w->pinyin_numbered, $pinyinNumbered)
            );

            $word = $loose->count() === 1 ? $loose->first() : null;
        }

        if (! $word instanceof DictionaryWord) {
            return TopicWordResolution::rejected(TopicRejection::NotFound);
        }

        return $this->gate($word);
    }

    /**
     * Mọi ứng viên ĐỦ ĐIỀU KIỆN cho một chữ Hán, để `TopicGenerator` chấm điểm
     * bằng nghĩa.
     *
     * Lọc cổng ngay ở đây: một cách đọc không có âm Hán-Việt hoặc có nghĩa bẩn
     * thì không phải ứng viên, kể cả khi nó là cách đọc phổ biến hơn.
     *
     * @return Collection<int, DictionaryWord>
     */
    public function candidatesFor(string $zh): Collection
    {
        return DictionaryWord::query()
            ->where('simplified', $zh)
            ->orderBy('id')
            ->get()
            ->filter(fn (DictionaryWord $w): bool => $this->gate($w)->isAccepted());
    }

    /**
     * VÌ SAO một chữ Hán không có ứng viên nào đủ điều kiện.
     *
     * `candidatesFor()` lọc im lặng, nên nếu không có hàm này thì mọi thất bại
     * đều bị đếm chung là "không tìm thấy" — và bảng tổng kết sẽ nói model bịa
     * trong khi thật ra từ điển thiếu âm Hán-Việt. Ba nguyên nhân đó đòi ba hành
     * động khác nhau.
     *
     * Trả lý do của dòng ĐI XA NHẤT qua các cổng: một chữ có hai cách đọc, một
     * cái thiếu Hán-Việt và một cái nghĩa bẩn, thì `dirty_gloss` mới là thứ
     * chặn nó — sửa được bằng cách nới cổng nghĩa, không phải bằng import lại.
     */
    public function diagnose(string $zh): TopicRejection
    {
        $rows = DictionaryWord::query()->where('simplified', $zh)->orderBy('id')->get();

        if ($rows->isEmpty()) {
            return TopicRejection::NotFound;
        }

        $furthest = TopicRejection::MissingHanViet;
        $order = [
            TopicRejection::MissingHanViet->value => 0,
            TopicRejection::MissingViGloss->value => 1,
            TopicRejection::DirtyGloss->value => 2,
        ];

        foreach ($rows as $row) {
            $rejection = $this->gate($row)->rejection;

            if ($rejection !== null && ($order[$rejection->value] ?? -1) > ($order[$furthest->value] ?? -1)) {
                $furthest = $rejection;
            }
        }

        return $furthest;
    }

    /**
     * Ba cổng, theo thứ tự rẻ trước.
     *
     * Cổng nghĩa DỌN TRƯỚC rồi mới loại, không loại thẳng khi thấy chữ Hán hay
     * mã pinyin. Đo trên tập ưu tiên: loại thẳng đánh rơi 195/8.013 hình chữ
     * (2,4%), trong đó 64 nằm trong top-2000 — và phần lớn là oan.
     * `梦` → "giấc mơ (LT: 場|场[chang2],個|个[ge4])" có nghĩa hoàn toàn dùng được
     * nằm ngoài ngoặc; vứt nó đi là bỏ mất từ "giấc mơ" khỏi chủ đề cảm xúc.
     * Chỉ mục SIÊU DỮ LIỆU (`biến thể của 碰[peng4]`) mới thật sự không dạy được.
     */
    private function gate(DictionaryWord $word): TopicWordResolution
    {
        if (! in_array($word->han_viet_status, [DictionaryWord::STATUS_OK, DictionaryWord::STATUS_MANUAL], true)
            || $word->han_viet === null) {
            return TopicWordResolution::rejected(TopicRejection::MissingHanViet);
        }

        if ($word->definitions_vi === null || $word->definitions_vi === []) {
            return TopicWordResolution::rejected(TopicRejection::MissingViGloss);
        }

        if (TopicGloss::teachingFrom($word->definitions_vi) === null) {
            return TopicWordResolution::rejected(TopicRejection::DirtyGloss);
        }

        return TopicWordResolution::accepted($word);
    }
}
