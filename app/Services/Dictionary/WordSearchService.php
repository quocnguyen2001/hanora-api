<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use App\Models\DictionaryWord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tìm kiếm từ điển có xếp hạng.
 *
 * Bảy nhánh, gán `rank` rồi `ORDER BY rank, frequency_rank NULLS LAST`. Thứ tự
 * này là hợp đồng với P7 — đổi nó là đổi cảm giác của cả màn tìm kiếm.
 *
 * | rank | Nhánh |
 * |---|---|
 * | 1 | khớp chính xác chữ Hán (giản thể hoặc phồn thể) |
 * | 2 | prefix chữ Hán |
 * | 3 | khớp chính xác pinyin |
 * | 4 | prefix pinyin |
 * | 5 | khớp/prefix âm Hán-Việt |
 * | 6 | full-text trên `search_tsv` **và** nghĩa tiếng Việt qua `VietnameseQueryBridge` |
 * | 7 | trigram trên pinyin — cứu chuỗi gõ sai |
 */
final class WordSearchService
{
    public const PER_PAGE = 20;

    /** Dưới ngưỡng này thì trigram trả về rác nhiều hơn kết quả. */
    private const TRIGRAM_THRESHOLD = 0.3;

    /**
     * Nghĩa tiếng Việt dùng CHUNG rank 6 với full-text, không phải một bậc riêng
     * bên dưới. Ba lần đo mới ra con số này, ghi lại để không ai đổi ngược:
     *
     * 1. **Rank 8 (dưới trigram) — hỏng.** `con mèo` phân loại thành lớp
     *    `pinyin`, vì `è` dùng chung codepoint với dấu thanh pinyin nên cố tình
     *    KHÔNG nằm trong bộ dấu-chỉ-tiếng-Việt của `QueryClassifier`. Nó bị nén
     *    thành `conmeo` và trigram trả 26 kết quả rác (从, 聪, 葱). Xếp sau đống
     *    đó thì 猫 rơi xuống vị trí 27 — trang 2, mà màn tìm kiếm không phân trang.
     * 2. **Rank 7 (trên trigram, dưới full-text) — vẫn hỏng cho lớp Việt.**
     *    `cảm ơn` trả 感情用事, 禁酒, 禁烟 trước: full-text khớp `cam & on` nhờ
     *    âm Hán-Việt `cấm` bỏ dấu thành `cam` cộng chữ `on` trong định nghĩa
     *    tiếng Anh. Rank 6 tự nó cũng đang trộn hai không gian như vậy.
     * 3. **Cùng rank 6 — đúng.** Cả hai đều là bằng chứng yếu; để
     *    `frequency_rank` phân xử thay vì áp đặt thứ tự nhánh. Đo lại:
     *    `cảm ơn` → 谢谢, `con mèo` → 猫, `hoc sinh` → 学生,
     *    `dien thoai` → 电话.
     *
     * Không nhánh cũ nào đổi số, nên thứ tự tương đối của chúng giữ nguyên.
     */
    private const RANK_VI_MEANING = 6;

    private const RANK_TRIGRAM = 7;

    /** `hv_not_found`: truy vấn trông như tiếng Việt nhưng không khớp âm nào. */
    public const HINT_HAN_VIET_NOT_FOUND = 'hv_not_found';

    /**
     * Mode do NGƯỜI DÙNG chọn, không phải máy đoán.
     *
     * `QueryClassifier` tồn tại chỉ vì một chuỗi latin mơ hồ giữa pinyin và
     * tiếng Việt, và mọi lỗi xếp hạng của nhánh nghĩa Việt đều là hệ quả của
     * việc đoán: `con mèo` bị nén thành `conmeo` rồi trigram trả về 从/聪/葱;
     * `xin chào` bỏ dấu bỏ cách thành `xinchao`, đúng `pinyin_plain` của 新潮.
     *
     * Mode hỏi thẳng. Ở `vi`, nhánh pinyin KHÔNG chạy nên cả hai triệu chứng
     * biến mất tại gốc, không phải bị vá bằng hạ bậc.
     *
     * `null` = đường auto cũ, giữ nguyên không sửa một dòng.
     */
    public const MODE_VI = 'vi';

    public const MODE_CN = 'cn';

    public function __construct(
        private readonly QueryClassifier $classifier,
        private readonly PinyinNormalizer $pinyin,
        private readonly VietnameseQueryBridge $bridge,
    ) {}

    /**
     * @return array{results: LengthAwarePaginator<int, object>, hint: string|null}
     */
    public function search(string $query, int $page, ?string $mode = null): array
    {
        $query = trim($query);
        $class = $this->classifier->classify($query);

        $results = $this->buildQuery($query, $class, $mode)
            ->orderBy('rank')
            ->orderByRaw('frequency_rank ASC NULLS LAST')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return [
            'results' => $results,
            'hint' => $this->hintFor($class, $mode, $results->total()),
        ];
    }

    private function buildQuery(string $query, string $class, ?string $mode): Builder
    {
        /*
         * Chữ Hán THẮNG TRƯỚC mode.
         *
         * CJK không mơ hồ nên mode không có gì để quyết — dán 学习 lúc đang ở
         * `vi` thì vẫn phải ra 学习. Mode chỉ quyết định cách hiểu chuỗi LATIN,
         * đúng chỗ duy nhất mà `QueryClassifier` phải đoán.
         */
        $branches = match (true) {
            $class === QueryClassifier::CLASS_HAN => $this->hanBranches($query),
            $mode === self::MODE_VI => $this->vietnameseBranches($query),
            $mode === self::MODE_CN => $this->chineseBranches($query),
            $class === QueryClassifier::CLASS_VIETNAMESE => $this->vietnameseBranches($query),
            default => $this->pinyinBranches($query),
        };

        /*
         * Mỗi nhánh là một SELECT riêng có `rank` cố định, gộp bằng UNION ALL
         * rồi khử trùng bằng DISTINCT ON (id) giữ `rank` nhỏ nhất.
         *
         * Cách này để Postgres dùng được index riêng của từng nhánh. Gộp tất cả
         * vào một WHERE với OR sẽ ép quét toàn bảng 120k dòng.
         */
        $union = null;

        foreach ($branches as $branch) {
            $union = $union === null ? $branch : $union->unionAll($branch);
        }

        if ($union === null) {
            // Không nhánh nào áp dụng: trả tập rỗng thay vì trả cả từ điển.
            $union = DB::table('dictionary_words')->selectRaw('*, 0 as rank')->whereRaw('false');
        }

        return DB::query()
            ->fromSub(
                DB::query()
                    ->selectRaw('DISTINCT ON (id) *')
                    ->fromSub($union, 'branches')
                    ->orderBy('id')
                    ->orderBy('rank'),
                'ranked'
            );
    }

    /**
     * @return list<Builder>
     */
    private function hanBranches(string $query): array
    {
        return [
            $this->branch(1, fn (Builder $q) => $q
                ->where('simplified', $query)
                ->orWhere('traditional', $query)),

            $this->branch(2, fn (Builder $q) => $q
                ->where('simplified', 'like', $this->escapeLike($query).'%')
                ->orWhere('traditional', 'like', $this->escapeLike($query).'%')),
        ];
    }

    /**
     * @return list<Builder>
     */
    private function vietnameseBranches(string $query): array
    {
        $plain = $this->pinyin->stripDiacritics($query);

        $branches = [
            $this->hanVietBranch($plain),
            $this->fullTextBranch($query),
        ];

        $meaning = $this->viMeaningBranch($query);

        if ($meaning !== null) {
            $branches[] = $meaning;
        }

        return $branches;
    }

    /**
     * Mode `cn` — người dùng nói rõ họ đang gõ tiếng Trung.
     *
     * KHÔNG có nhánh Hán-Việt, KHÔNG có cầu nối nghĩa tiếng Việt, và KHÔNG gọi
     * `bridge->resolve()` — bỏ luôn một lượt tra bảng lexicon trên đường nóng
     * chứ không chỉ bỏ nhánh.
     *
     * Full-text định nghĩa tiếng Anh vẫn chạy: `student` phải ra 学生 ở cả hai
     * mode.
     *
     * @return list<Builder>
     */
    private function chineseBranches(string $query): array
    {
        $normalized = $this->pinyin->plain($query);
        $branches = [];

        if ($normalized !== '') {
            $branches[] = $this->branch(3, fn (Builder $q) => $q->where('pinyin_plain', $normalized));
            $branches[] = $this->branch(4, fn (Builder $q) => $q
                ->where('pinyin_plain', 'like', $this->escapeLike($normalized).'%'));
        }

        $branches[] = $this->fullTextBranch($query);

        if ($normalized !== '') {
            $branches[] = $this->trigramBranch($normalized);
        }

        return $branches;
    }

    /**
     * Trigram trên `pinyin_plain` — cứu chuỗi gõ sai.
     *
     * Toán tử `%`, KHÔNG phải `similarity(...) >= x`.
     *
     * `similarity()` là lời gọi hàm nên planner không dùng được index GIN
     * trigram — đo được: seq scan toàn bộ 123.646 dòng, 260ms. `%` là toán tử mà
     * index hiểu: **0,2ms**, nhanh hơn hơn 1000 lần. Chính benchmark bắt buộc
     * của R2 lộ ra chuyện này.
     *
     * `similarity() >= ?` đứng sau vẫn giữ lại làm chốt chặn ngưỡng: `%` phụ
     * thuộc GUC `pg_trgm.similarity_threshold` (mặc định 0.3), còn dòng này khóa
     * ngưỡng bằng con số tường minh. Nó chỉ chạy trên số ít dòng mà index đã lọc
     * ra nên không tốn gì.
     */
    private function trigramBranch(string $normalized): Builder
    {
        return $this->branch(self::RANK_TRIGRAM, fn (Builder $q) => $q
            ->whereRaw('pinyin_plain % ?', [$normalized])
            ->whereRaw('similarity(pinyin_plain, ?) >= ?', [$normalized, self::TRIGRAM_THRESHOLD]));
    }

    /**
     * @return list<Builder>
     */
    private function pinyinBranches(string $query): array
    {
        $normalized = $this->pinyin->plain($query);
        $meaningTerms = $this->bridge->resolve($query);
        $branches = [];

        /*
         * Truy vấn CÓ DẤU mà cầu nối tra được → hạ nhánh pinyin xuống dưới nghĩa
         * tiếng Việt.
         *
         * `xin chào` bỏ dấu và bỏ cách thành `xinchao`, đúng bằng `pinyin_plain`
         * của 新潮 (xīncháo). Nhánh pinyin khớp chính xác là rank 3, nên 新潮,
         * 新朝, 心潮澎湃 đứng trước 你好 — người dùng chào bằng tiếng Việt và
         * nhận về ba từ Hán không liên quan.
         *
         * Tín hiệu phân biệt là DẤU, không phải dấu cách. Đo được: `ni hao` và
         * `xue xi` (pinyin gõ tách) CŨNG tra được ra nghĩa tiếng Việt — `hao` là
         * "hao mòn", `xi` là một từ thật — nên "có cách + tra được" quá yếu, dùng
         * nó sẽ phá luôn người gõ `ni hao` để tìm 你好. Người học gõ pinyin hầu
         * như luôn gõ không dấu; `chào` thì bàn phím tiếng Việt mới sinh ra.
         *
         * Hạ bậc chứ KHÔNG bỏ nhánh: 新潮 vẫn còn trong kết quả, chỉ đứng sau.
         * Va chạm này hiếm — đo được 703/49.491 cụm tiếng Việt nhiều tiếng (1,4%)
         * có dạng gộp trùng `pinyin_plain` của một từ Hán nào đó.
         */
        $demotePinyin = $meaningTerms !== [] && $query !== $this->pinyin->stripDiacritics($query);

        if ($normalized !== '') {
            $branches[] = $this->branch($demotePinyin ? 8 : 3, fn (Builder $q) => $q
                ->where('pinyin_plain', $normalized));
            $branches[] = $this->branch($demotePinyin ? 9 : 4, fn (Builder $q) => $q
                ->where('pinyin_plain', 'like', $this->escapeLike($normalized).'%'));
        }

        /*
         * Chuỗi latin không dấu mơ hồ giữa pinyin và Hán-Việt (`hoc tap`), nên
         * chạy CẢ nhánh Hán-Việt rồi để xếp hạng quyết định. Người Việt hay gõ
         * không dấu, đây là ca thường gặp chứ không phải ngoại lệ.
         */
        if ($this->classifier->mayBeVietnamese($query)) {
            $branches[] = $this->hanVietBranch($this->pinyin->stripDiacritics($query));
        }

        $branches[] = $this->fullTextBranch($query);

        /*
         * `mayBeVietnamese()` KHÔNG phải cổng lọc — nó trả `true` cho mọi truy
         * vấn latin không rỗng. Cổng thật là `resolve()` trả về rỗng hay không,
         * và nó nằm trong `viMeaningBranch()`. Đây cũng là lý do `con mèo` tới
         * được đây: nó phân loại thành lớp pinyin, không phải lớp Việt.
         */
        $meaning = $this->viMeaningBranch($query, $meaningTerms);

        if ($meaning !== null) {
            $branches[] = $meaning;
        }

        if ($normalized !== '') {
            $branches[] = $demotePinyin
                ? $this->branch(10, fn (Builder $q) => $q
                    ->whereRaw('pinyin_plain % ?', [$normalized])
                    ->whereRaw('similarity(pinyin_plain, ?) >= ?', [$normalized, self::TRIGRAM_THRESHOLD]))
                : $this->trigramBranch($normalized);
        }

        return $branches;
    }

    private function hanVietBranch(string $plainQuery): Builder
    {
        /*
         * `f_unaccent` chứ không phải `unaccent`: phải đúng wrapper IMMUTABLE
         * mà P4 dùng khi sinh cột, nếu không Postgres sẽ không dùng index.
         */
        return $this->branch(5, fn (Builder $q) => $q
            ->whereRaw('han_viet_plain = f_unaccent(?)', [$plainQuery])
            ->orWhereRaw('han_viet_plain LIKE f_unaccent(?) || \'%\'', [$this->escapeLike($plainQuery)]));
    }

    private function fullTextBranch(string $query): Builder
    {
        return $this->branch(6, fn (Builder $q) => $q
            ->whereRaw("search_tsv @@ plainto_tsquery('simple', f_unaccent(?))", [$query]));
    }

    /**
     * Nhánh nghĩa tiếng Việt — `null` khi không cầu nối được.
     *
     * Trả `null` thay vì một builder rỗng để câu SQL không mang theo một nhánh
     * UNION không bao giờ khớp: phần lớn truy vấn latin không phải tiếng Việt.
     *
     * `plainto_tsquery` chứ KHÔNG phải `phraseto_tsquery`: bridge đã gỡ hư từ
     * tiếng Anh khỏi từng nghĩa nên chỉ còn từ nội dung, và truy vấn cụm sẽ kéo
     * posting list của `to` (32.506/123.646 dòng) làm GIN index bị bỏ qua.
     *
     * @param  list<string>|null  $terms  Kết quả `resolve()` đã có sẵn, để nhánh
     *                                    pinyin và nhánh này không tra hai lần.
     */
    private function viMeaningBranch(string $query, ?array $terms = null): ?Builder
    {
        $terms ??= $this->bridge->resolve($query);

        if ($terms === []) {
            return null;
        }

        /*
         * `$pieces` dựng từ SỐ LƯỢNG phần tử, không phải nội dung — mọi từ khóa
         * đi qua binding. Guard mảng rỗng nằm ngay trên, trong thân hàm, chứ
         * không dựa vào quy ước ở call site: `implode` trên mảng rỗng cho `''`,
         * và `search_tsv @@ ()` là lỗi cú pháp chứ không phải tập rỗng.
         */
        $pieces = implode(' || ', array_fill(
            0, count($terms), "plainto_tsquery('simple', f_unaccent(?))"
        ));

        /*
         * HAI điều kiện, không phải một.
         *
         * `search_tsv` là `to_tsvector('simple', f_unaccent(han_viet || defs))` —
         * nó TRỘN âm Hán-Việt với định nghĩa tiếng Anh vào cùng một vector. Nên
         * từ khóa tiếng Anh va vào không gian Hán-Việt đã bỏ dấu: tra `con mèo`
         * cho ra từ khóa `cat`, và `cat` khớp 吃 vì âm Hán-Việt của nó là `cật`,
         * bỏ dấu thành `cat`. 吃 có `frequency_rank` tốt hơn 猫 nên đứng trước —
         * đo được: 吃, 吃饭, rồi mới tới 猫.
         *
         * Điều kiện một chạy trên GIN index và thu hẹp còn vài trăm dòng; điều
         * kiện hai tính lại tsvector CHỈ trên phần định nghĩa để loại những dòng
         * chỉ khớp nhờ âm Hán-Việt. Nó là lời gọi hàm nên không dùng index được,
         * nhưng chỉ chạy trên tập đã lọc — cùng khuôn với `similarity()` đứng sau
         * toán tử `%` ở nhánh trigram.
         */
        return $this->branch(self::RANK_VI_MEANING, fn (Builder $q) => $q
            ->whereRaw("search_tsv @@ ({$pieces})", $terms)
            ->whereRaw("to_tsvector('simple', f_unaccent(definitions_en_text)) @@ ({$pieces})", $terms));
    }

    /**
     * @param  callable(Builder): Builder  $where
     */
    private function branch(int $rank, callable $where): Builder
    {
        /*
         * `?::int`, không phải `?` trần.
         *
         * PDO gửi tham số xuống dạng chuỗi, nên Postgres suy ra kiểu `text` cho
         * cột `rank` và `ORDER BY rank` sắp xếp theo THỨ TỰ CHỮ. Với rank 1–7 thì
         * text-sort trùng numeric-sort nên không ai thấy gì; ngay khi có rank hai
         * chữ số thì `'10' < '6'` và cả bảng xếp hạng lật ngược — đo được: mọi
         * nhánh yếu nhất nhảy lên đầu, `con mèo` trả 从/聪/葱 thay vì 猫.
         */
        $builder = DB::table('dictionary_words')->selectRaw('*, ?::int as rank', [$rank]);

        return $where($builder);
    }

    /**
     * `%` và `_` trong chuỗi người dùng gõ phải là ký tự thường, không phải
     * wildcard — nếu không thì `?q=%` quét sạch bảng.
     */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private function hintFor(string $class, ?string $mode, int $total): ?string
    {
        if ($total > 0) {
            return null;
        }

        /*
         * Chỉ gợi ý khi người dùng rõ ràng đang tìm bằng tiếng Việt. Độ phủ
         * Hán-Việt chưa đạt 100%, nên "không thấy" có thể là do dữ liệu chứ
         * không phải do người dùng gõ sai.
         *
         * Mode `vi` là bằng chứng MẠNH HƠN hẳn `classify()` — người dùng tự nói
         * ra. Mode `cn` thì dứt khoát không phát hint này.
         *
         * Không thêm giá trị hint mới. Giới hạn cũ vẫn nguyên: hint không phân
         * biệt được "chưa có âm Hán-Việt" với "không cầu nối được nghĩa Việt",
         * và im lặng khi cầu nối trả kết quả kém.
         */
        /*
         * Truy vấn chữ Hán KHÔNG bao giờ nhận hint này, kể cả ở mode `vi`.
         *
         * `buildQuery()` đặt chữ Hán TRƯỚC mode, nên một truy vấn CJK đã chạy
         * `hanBranches()` — độ phủ âm Hán-Việt không liên quan gì tới việc nó
         * không ra kết quả. Phát hint ở đây là dẫn người dùng vào ngõ cụt: FE sẽ
         * khuyên "thử chuyển sang 中文", mà theo đúng thiết kế thì chuyển sang
         * cũng chạy y hệt nhánh đó.
         */
        if ($class === QueryClassifier::CLASS_HAN) {
            return null;
        }

        $searchingInVietnamese = $mode === self::MODE_VI
            || ($mode === null && $class === QueryClassifier::CLASS_VIETNAMESE);

        return $searchingInVietnamese ? self::HINT_HAN_VIET_NOT_FOUND : null;
    }

    /**
     * Ép kiểu model cho controller — paginator ở trên trả về stdClass.
     *
     * @param  list<object>  $rows
     * @return list<DictionaryWord>
     */
    public function hydrate(array $rows): array
    {
        return array_map(
            fn (object $row): DictionaryWord => (new DictionaryWord)->newFromBuilder((array) $row),
            $rows
        );
    }
}
