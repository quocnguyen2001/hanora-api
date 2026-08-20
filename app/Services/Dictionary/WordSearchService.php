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
 * | 6 | full-text trên `search_tsv` (âm Hán-Việt + định nghĩa tiếng Anh) |
 * | 7 | trigram trên pinyin — cứu chuỗi gõ sai |
 */
final class WordSearchService
{
    public const PER_PAGE = 20;

    /** Dưới ngưỡng này thì trigram trả về rác nhiều hơn kết quả. */
    private const TRIGRAM_THRESHOLD = 0.3;

    /** `hv_not_found`: truy vấn trông như tiếng Việt nhưng không khớp âm nào. */
    public const HINT_HAN_VIET_NOT_FOUND = 'hv_not_found';

    public function __construct(
        private readonly QueryClassifier $classifier,
        private readonly PinyinNormalizer $pinyin,
    ) {}

    /**
     * @return array{results: LengthAwarePaginator<int, object>, hint: string|null}
     */
    public function search(string $query, int $page): array
    {
        $query = trim($query);
        $class = $this->classifier->classify($query);

        $results = $this->buildQuery($query, $class)
            ->orderBy('rank')
            ->orderByRaw('frequency_rank ASC NULLS LAST')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return [
            'results' => $results,
            'hint' => $this->hintFor($class, $results->total()),
        ];
    }

    private function buildQuery(string $query, string $class): Builder
    {
        $branches = match ($class) {
            QueryClassifier::CLASS_HAN => $this->hanBranches($query),
            QueryClassifier::CLASS_VIETNAMESE => $this->vietnameseBranches($query),
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

        return [
            $this->hanVietBranch($plain),
            $this->fullTextBranch($query),
        ];
    }

    /**
     * @return list<Builder>
     */
    private function pinyinBranches(string $query): array
    {
        $normalized = $this->pinyin->plain($query);
        $branches = [];

        if ($normalized !== '') {
            $branches[] = $this->branch(3, fn (Builder $q) => $q->where('pinyin_plain', $normalized));
            $branches[] = $this->branch(4, fn (Builder $q) => $q
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

        if ($normalized !== '') {
            /*
             * Toán tử `%`, KHÔNG phải `similarity(...) >= x`.
             *
             * `similarity()` là lời gọi hàm nên planner không dùng được index
             * GIN trigram — đo được: seq scan toàn bộ 123.646 dòng, 260ms.
             * `%` là toán tử mà index hiểu: **0,2ms**, nhanh hơn hơn 1000 lần.
             * Chính benchmark bắt buộc của R2 lộ ra chuyện này.
             *
             * `similarity() >= ?` đứng sau vẫn giữ lại, làm chốt chặn ngưỡng:
             * `%` phụ thuộc GUC `pg_trgm.similarity_threshold` (mặc định 0.3),
             * còn dòng này khóa ngưỡng bằng con số tường minh. Nó chỉ chạy trên
             * số ít dòng mà index đã lọc ra, nên không tốn gì.
             */
            $branches[] = $this->branch(7, fn (Builder $q) => $q
                ->whereRaw('pinyin_plain % ?', [$normalized])
                ->whereRaw('similarity(pinyin_plain, ?) >= ?', [$normalized, self::TRIGRAM_THRESHOLD]));
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
     * @param  callable(Builder): Builder  $where
     */
    private function branch(int $rank, callable $where): Builder
    {
        $builder = DB::table('dictionary_words')->selectRaw('*, ? as rank', [$rank]);

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

    private function hintFor(string $class, int $total): ?string
    {
        if ($total > 0) {
            return null;
        }

        // Chỉ gợi ý khi người dùng rõ ràng đang gõ tiếng Việt. Độ phủ Hán-Việt
        // chưa đạt 100%, nên "không thấy" có thể là do dữ liệu chứ không phải
        // do người dùng gõ sai — P7 dùng hint này để nói đúng chuyện đó.
        return $class === QueryClassifier::CLASS_VIETNAMESE
            ? self::HINT_HAN_VIET_NOT_FOUND
            : null;
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
