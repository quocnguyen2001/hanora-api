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
 * Bảy nhánh, gán `rank` rồi `ORDER BY rank, precision, frequency_rank NULLS LAST`.
 * Thứ tự này là hợp đồng với P7 — đổi nó là đổi cảm giác của cả màn tìm kiếm.
 *
 * | rank | Nhánh |
 * |---|---|
 * | 1 | khớp chính xác chữ Hán (giản thể hoặc phồn thể) |
 * | 2 | prefix chữ Hán |
 * | 3 | khớp chính xác pinyin |
 * | 4 | prefix pinyin |
 * | 5 | khớp/prefix âm Hán-Việt |
 * | 6 | full-text trên `search_tsv` **và** nghĩa tiếng Việt trên `definitions_vi` |
 * | 7 | trigram trên pinyin — cứu chuỗi gõ sai |
 *
 * `precision` là bậc phụ BÊN TRONG một rank, không phải rank mới. Chỉ nhánh
 * nghĩa tiếng Việt phát ra giá trị khác mặc định; xem `viMeaningBranch()`.
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

    /**
     * `precision` mặc định — bậc phụ bên trong một rank, nhỏ hơn là tốt hơn.
     *
     * Giá trị này là bậc XẤU NHẤT, không phải 0. Nó là thứ mọi nhánh KHÔNG
     * phải nghĩa tiếng Việt phát ra, và full-text tiếng Anh dùng chung rank 6
     * với nhánh nghĩa Việt — nên đặt mặc định 0 sẽ đẩy mọi kết quả tiếng Anh
     * lên trên mọi tầng nghĩa Việt, tức lật ngược đúng thứ phase này xây.
     *
     * Truy vấn tiếng Anh (`student`) chỉ khớp full-text nên TOÀN BỘ kết quả đều
     * mang giá trị này, và thứ tự của chúng không đổi một dòng nào.
     */
    private const PRECISION_DEFAULT = 6;

    /** Dưới ngưỡng này thì mọi nghĩa đều khớp một cái gì đó. */
    private const MIN_MEANING_LENGTH = 3;

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
        private readonly VietnameseQueryNormalizer $normalizer,
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
            ->orderBy('precision')
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
            $union = DB::table('dictionary_words')
                ->selectRaw('*, 0 as rank, '.self::PRECISION_DEFAULT.' as precision')
                ->whereRaw('false');
        }

        /*
         * `ORDER BY id, rank, precision` — `precision` ở đây KHÔNG thừa.
         *
         * Một dòng có thể tới từ hai nhánh CÙNG rank 6: full-text tiếng Anh
         * (precision 6) và nghĩa tiếng Việt (precision 0–5). Chỉ sắp theo
         * `rank` thì hai dòng đó hòa, `DISTINCT ON` giữ dòng nào là tùy
         * Postgres, và bậc nghĩa Việt biến mất một cách ngẫu nhiên.
         */
        return DB::query()
            ->fromSub(
                DB::query()
                    ->selectRaw('DISTINCT ON (id) *')
                    ->fromSub($union, 'branches')
                    ->orderBy('id')
                    ->orderBy('rank')
                    ->orderBy('precision'),
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
            $this->hanVietBranch($plain, $this->accentedForm($query)),
            $this->fullTextBranch($query),
        ];

        foreach ([false, true] as $useAiGlosses) {
            $meaning = $this->viMeaningBranch($query, $useAiGlosses);

            if ($meaning !== null) {
                $branches[] = $meaning;
            }
        }

        return $branches;
    }

    /**
     * Nhánh nghĩa tiếng Việt — khớp THẲNG lên `definitions_vi`, không qua cầu nối.
     *
     * Trả `null` khi truy vấn quá ngắn để mang tín hiệu; nhánh không được gắn
     * vào UNION thay vì gắn một nhánh không bao giờ khớp.
     *
     * ## Một vector, chọn theo truy vấn — không phải cả hai
     *
     * Truy vấn CÓ DẤU đi `search_vi_tsv`; truy vấn không dấu đi
     * `search_vi_plain_tsv`. Đây là kết quả đo, không phải sở thích:
     *
     * - Bỏ dấu CẢ HAI vế cho ra rác — `chó`→你/他/吗, `bàn`→你/我们,
     *   `táo`→么/远/秀, vì `cho`/`ban`/`tao` có mặt khắp nơi trong định nghĩa.
     *   Tiếng Việt đầy cặp tối thiểu chỉ khác thanh điệu; bỏ dấu phá nó nặng
     *   hơn phá pinyin rất nhiều.
     * - Chạy CẢ HAI vector rồi xếp bậc cũng hỏng, theo chiều ngược lại:
     *   `may tinh` khớp `may` và `tinh` CÓ DẤU trong nghĩa của 吉凶 ("may mắn
     *   hay xui xẻo"), nên một khớp trùng hợp ngẫu nhiên ở bậc cao đứng trước
     *   电脑 ở bậc thấp. Đo được: 吉凶, 祸福吉凶, rồi mới tới 电脑.
     *
     * Mỗi vector phục vụ đúng lớp truy vấn nó tồn tại vì. `precision` 0–2 là
     * đường có dấu, 3–5 là đường không dấu — số khác nhau để `ORDER BY` vẫn
     * nói đúng thứ tự nếu sau này có ai nối hai đường lại.
     *
     * ## Ba bậc, và vì sao bậc 0 so với truy vấn GỐC
     *
     * | precision | Điều kiện |
     * |---|---|
     * | 0 / 3 | nghĩa ĐẦU đúng bằng truy vấn gốc |
     * | 1 / 4 | nghĩa ĐẦU có chứa truy vấn (nguyên cụm, theo biên từ) |
     * | 2 / 5 | khớp ở đâu đó trong các nghĩa |
     *
     * Bậc 0 so với truy vấn GỐC — trước khi bỏ loại từ — và đó là thứ giữ cho
     * việc bỏ loại từ không phá những ngữ mà loại từ là một phần của nghĩa:
     *
     *   `con mèo` → bỏ `con`, tra `mèo` → 猫 bậc 1 (nghĩa đầu là "mèo…")
     *   `quả táo` → bỏ `quả`, tra `táo`, nhưng nghĩa đầu của 苹果 ĐÚNG BẰNG
     *               "quả táo" → bậc 0, đứng trên 清醒 ("tỉnh táo") ở bậc 1
     *
     * Không có bậc 0 thì `quả táo` tụt xuống thành `táo`, và `táo` là ca đa
     * nghĩa mà plan đã chấp nhận là không sửa được.
     *
     * Chỉ xét nghĩa ĐẦU, không phải mọi nghĩa. Đây là lựa chọn có đo, theo cả
     * hai hướng:
     *
     * - Chất lượng: `hoc sinh` khớp trọn vẹn nghĩa đầu của 学生, nhưng cũng
     *   khớp trọn vẹn một nghĩa PHÍA SAU của 生 — mà 生 có tần suất tốt hơn nên
     *   nó thắng. Nghĩa đầu là nghĩa chính; CVDICT xếp nó trước có lý do.
     * - Chi phí: quét mọi nghĩa cần `jsonb_array_elements_text` trên TỪNG dòng
     *   GIN trả về. Trên ca fan-out cao nhất (`nguoi`, 5.909 dòng) riêng nó là
     *   +32ms, đủ để vượt ngưỡng 150ms của P6.
     *
     * Hai cột `definitions_vi_first*` dựng sẵn nghĩa đầu đã thường hóa, nên
     * `CASE` chỉ còn so chuỗi. `strpos` với hai đầu chèn dấu cách là cách khớp
     * THEO BIÊN TỪ mà không cần gọi `to_tsvector` trên từng dòng — cũng đo được
     * là +35ms nếu gọi.
     */
    private function viMeaningBranch(string $query, bool $useAiGlosses = false): ?Builder
    {
        $normalized = $this->normalizer->normalize($query);

        if (mb_strlen($normalized) < self::MIN_MEANING_LENGTH) {
            return null;
        }

        $search = $this->normalizer->withoutLeadingClassifier($normalized);
        $accented = $this->normalizer->isAccented($normalized);

        /*
         * Hai bộ cột CÙNG hình dạng, chạy CÙNG rank và CÙNG thang bậc:
         *
         *   CVDICT  search_vi_tsv         definitions_vi_first
         *   AI      search_vi_ai_tsv      definitions_vi_ai_first
         *
         * Nhánh AI là nhánh THÊM, không thay nhánh cũ. `DISTINCT ON (id)` giữ
         * `precision` nhỏ hơn, nên một từ khớp cả hai lấy bậc tốt hơn, và từ
         * chưa được dọn (cột AI còn NULL) không mất gì — `@@` trên NULL trả
         * NULL nên nó chỉ vắng mặt ở nhánh AI.
         *
         * Vì sao cần: nghĩa CVDICT xếp không theo mức phổ biến, và mọi bậc
         * `precision` đều tính trên nghĩa ĐẦU. Đo được `的` có nghĩa đầu là
         * "xe taxi", `吗` là "dùng trong 嗎啡" — tức bậc 0 và 1 đang được trao
         * cho nghĩa sai ở đúng nhóm từ phổ biến nhất.
         */
        $suffix = $useAiGlosses ? '_ai' : '';
        $vector = $accented ? "search_vi{$suffix}_tsv" : "search_vi{$suffix}_plain_tsv";
        $first = $accented
            ? "definitions_vi{$suffix}_first"
            : "definitions_vi{$suffix}_first_plain";
        $base = $accented ? 0 : 3;

        // Vế bỏ dấu bọc CẢ HAI phía bằng `f_unaccent` — cùng wrapper IMMUTABLE
        // mà cột generated dùng, nếu không planner sẽ không đụng tới index.
        $tsquery = $accented
            ? "plainto_tsquery('simple', ?)"
            : "plainto_tsquery('simple', f_unaccent(?))";
        $term = $accented ? '?' : 'f_unaccent(?)';

        /*
         * `f_unaccent(?)` trên một tham số là hằng theo dòng, nên Postgres tính
         * nó MỘT lần cho cả câu — khác hẳn `f_unaccent(cột)`, thứ chạy trên từng
         * dòng và là 36ms đã đo.
         *
         * `CASE` chỉ chạy trên tập mà GIN index đã lọc ra qua `WHERE`, cùng khuôn
         * với `similarity()` đứng sau toán tử `%` ở nhánh trigram.
         */
        $precision = <<<SQL
            CASE
                WHEN {$first} = {$term} THEN {$base}
                WHEN strpos(' ' || {$first} || ' ', ' ' || {$term} || ' ') > 0 THEN {$base}+1
                ELSE {$base}+2
            END
        SQL;

        return $this->branch(
            self::RANK_VI_MEANING,
            fn (Builder $q) => $q->whereRaw("{$vector} @@ {$tsquery}", [$search]),
            $precision,
            [$normalized, $search],
        );
    }

    /**
     * Mode `cn` — người dùng nói rõ họ đang gõ tiếng Trung.
     *
     * KHÔNG có nhánh Hán-Việt và KHÔNG có nhánh nghĩa tiếng Việt: ai chọn
     * `中文` thì đang gõ tiếng Trung, và hai nhánh đó chỉ sinh nhiễu.
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
        $meaning = $this->viMeaningBranch($query);
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
         * `xue xi` (pinyin gõ tách) CŨNG khớp nghĩa tiếng Việt — `hao` có trong
         * "hao mòn", `xi` là một từ thật — nên "có cách + khớp được" quá yếu,
         * dùng nó sẽ phá luôn người gõ `ni hao` để tìm 你好. Người học gõ pinyin
         * hầu như luôn gõ không dấu; `chào` thì bàn phím tiếng Việt mới sinh ra.
         *
         * Vế thứ ba là một lượt `EXISTS` trên GIN index, chạy ĐÚNG MỘT LẦN cho
         * mỗi truy vấn có dấu trên đường auto. Nó thay cho `resolve()` của cầu
         * nối cũ: không có nó thì `xuéxí` — pinyin có dấu thanh — cũng bị hạ
         * bậc, và 学习 rơi khỏi vị trí 1.
         *
         * Hạ bậc chứ KHÔNG bỏ nhánh: 新潮 vẫn còn trong kết quả, chỉ đứng sau.
         * Va chạm này hiếm — đo được 703/49.491 cụm tiếng Việt nhiều tiếng (1,4%)
         * có dạng gộp trùng `pinyin_plain` của một từ Hán nào đó.
         */
        $demotePinyin = $meaning !== null
            && $query !== $this->pinyin->stripDiacritics($query)
            && (clone $meaning)->exists();

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
            $branches[] = $this->hanVietBranch(
                $this->pinyin->stripDiacritics($query),
                $this->accentedForm($query),
            );
        }

        $branches[] = $this->fullTextBranch($query);

        /*
         * `mayBeVietnamese()` KHÔNG phải cổng lọc — nó trả `true` cho mọi truy
         * vấn latin không rỗng. Nhánh nghĩa Việt tự lọc bằng độ dài tối thiểu và
         * bằng chính GIN index. Đây cũng là lý do `con mèo` tới được đây: nó
         * phân loại thành lớp pinyin, không phải lớp Việt.
         */
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

    /**
     * Nhánh âm Hán-Việt (rank 5).
     *
     * `$accentedQuery` khác `null` khi người dùng gõ CÓ DẤU. Khi đó nhánh này
     * phải kiểm lại trên `han_viet` còn nguyên dấu, và đây không phải tinh chỉnh
     * — nó là chính phát hiện của phase này, áp lên một tầng khác.
     *
     * Đo được: `chó` khớp 23 dòng qua prefix bỏ dấu `cho%` — 撑 (`chống`),
     * 帚 (`chổi`), 肘 (`chỏ`), 肘子 (`chỏ tử`). Không dòng nào đọc là `chó`;
     * chúng chỉ va vào nhau SAU KHI bỏ dấu. Rank 5 đứng trên rank 6, nên cả 23
     * dòng đó đẩy 狗 — từ có nghĩa tiếng Việt đúng bằng `chó` — ra khỏi trang.
     *
     * Vế bỏ dấu vẫn là vế chạy trên index và thu hẹp còn vài chục dòng; vế có
     * dấu chỉ lọc trên tập đó nên không tốn gì. Cùng khuôn với `similarity()`
     * đứng sau toán tử `%` ở nhánh trigram.
     *
     * Truy vấn KHÔNG dấu (`hoc tap`) đi đường cũ không đổi một dòng nào: người
     * gõ không dấu vốn đã chấp nhận sự mơ hồ đó.
     */
    private function hanVietBranch(string $plainQuery, ?string $accentedQuery = null): Builder
    {
        return $this->branch(5, function (Builder $q) use ($plainQuery, $accentedQuery): Builder {
            /*
             * `f_unaccent` chứ không phải `unaccent`: phải đúng wrapper IMMUTABLE
             * mà P4 dùng khi sinh cột, nếu không Postgres sẽ không dùng index.
             */
            $q->where(fn (Builder $sub): Builder => $sub
                ->whereRaw('han_viet_plain = f_unaccent(?)', [$plainQuery])
                ->orWhereRaw('han_viet_plain LIKE f_unaccent(?) || \'%\'', [$this->escapeLike($plainQuery)]));

            if ($accentedQuery === null) {
                return $q;
            }

            return $q->where(fn (Builder $sub): Builder => $sub
                ->whereRaw('lower(han_viet) = ?', [$accentedQuery])
                ->orWhereRaw('lower(han_viet) LIKE ? || \'%\'', [$this->escapeLike($accentedQuery)]));
        });
    }

    /**
     * Dạng có dấu đã thường hóa của truy vấn, hoặc `null` nếu nó vốn không dấu.
     */
    private function accentedForm(string $query): ?string
    {
        $normalized = $this->normalizer->normalize($query);

        return $this->normalizer->isAccented($normalized) ? $normalized : null;
    }

    private function fullTextBranch(string $query): Builder
    {
        return $this->branch(6, fn (Builder $q) => $q
            ->whereRaw("search_tsv @@ plainto_tsquery('simple', f_unaccent(?))", [$query]));
    }

    /**
     * `?::int`, không phải `?` trần.
     *
     * PDO gửi tham số xuống dạng chuỗi, nên Postgres suy ra kiểu `text` cho cột
     * `rank` và `ORDER BY rank` sắp xếp theo THỨ TỰ CHỮ. Với rank 1–7 thì
     * text-sort trùng numeric-sort nên không ai thấy gì; ngay khi có rank hai
     * chữ số thì `'10' < '6'` và cả bảng xếp hạng lật ngược — đo được: mọi nhánh
     * yếu nhất nhảy lên đầu, `con mèo` trả 从/聪/葱 thay vì 猫.
     *
     * `precision` chịu đúng ràng buộc đó nên cũng phải là số, không phải chuỗi.
     * Mặc định là hằng số ghép thẳng vào SQL — nó không tới từ người dùng.
     *
     * @param  callable(Builder): Builder  $where
     * @param  string|null  $precision  Biểu thức SQL cho `precision`; `null` là bậc mặc định.
     * @param  list<string>  $bindings  Tham số của biểu thức trên, theo đúng thứ tự.
     */
    private function branch(int $rank, callable $where, ?string $precision = null, array $bindings = []): Builder
    {
        $expression = $precision ?? (string) self::PRECISION_DEFAULT;

        // Binding của `selectRaw` được compile TRƯỚC binding của `where`, nên
        // thứ tự ở đây là thứ tự thật trong câu lệnh.
        $builder = DB::table('dictionary_words')
            ->selectRaw("*, ?::int as rank, ({$expression})::int as precision", [$rank, ...$bindings]);

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
