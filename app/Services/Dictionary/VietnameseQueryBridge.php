<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use App\Models\ViEnLexiconEntry;
use Illuminate\Support\Facades\Cache;
use Normalizer;

/**
 * Dịch truy vấn tiếng Việt sang từ khóa tiếng Anh để bắc cầu sang `search_tsv`.
 *
 * `con mèo` → span `mèo` → nghĩa thô `["cat"]` → từ khóa `["cat"]`
 *
 * Một service, hai người dùng: nhánh rank 6 của `WordSearchService` và bộ lọc
 * kho từ của `VocabularyController`. Trả rỗng nghĩa là "không cầu nối được", và
 * đó là cổng thật sự quyết định nhánh có chạy hay không —
 * `QueryClassifier::mayBeVietnamese()` trả `true` cho MỌI truy vấn latin không
 * rỗng nên nó không lọc gì cả.
 */
final class VietnameseQueryBridge
{
    /** Dưới ngưỡng này thì mọi thứ đều khớp một cái gì đó. */
    private const MIN_LENGTH = 3;

    /** Trần từ khóa. Siết con số này là cách vá nhiễu RẺ NHẤT — chỉ cần deploy. */
    private const MAX_TERMS = 6;

    /** Nghĩa dài hơn ngần này từ nội dung là câu giải thích, không phải từ khóa. */
    private const MAX_WORDS_PER_SENSE = 4;

    /** Chặn bùng nổ tổ hợp span trên truy vấn dài. */
    private const MAX_TOKENS = 8;

    /**
     * Trần số dòng lexicon lấy về cho một lần tra.
     *
     * `MAX_TOKENS = 8` cho tối đa 36 span (8+7+…+1), và đo trên truy vấn thật
     * nhiều token nhất (`nguoi dan ong do`) chỉ ra 24 dòng. Trần cắt theo
     * `term ASC`, nên đặt sát số đo là tự chuốc rủi ro: một span dài đúng nhưng
     * `term` xếp cuối bảng chữ cái sẽ bị đồng âm ngắn xếp đầu đuổi ra, im lặng.
     */
    private const MAX_ROWS = 256;

    /**
     * Hư từ đứng đầu ngữ tiếng Việt — loại từ và tiểu từ lịch sự.
     *
     * Chúng KHÔNG bị cắt khỏi truy vấn. Chúng chỉ bị xếp XUỐNG CUỐI trong nhóm
     * span cùng độ dài, để trung tâm ngữ được xét trước:
     *
     *   con mèo   → span 1 token: `mèo` trước `con`  → "cat", không phải "child"
     *   xin chào  → span 1 token: `chào` trước `xin` → "hello", không phải "ask"
     *   mèo đen   → không có hư từ → giữ trái sang phải → `mèo`, không phải `đen`
     *
     * Xếp lại chứ không CẮT là điểm quan trọng: `xin lỗi` và `xin phép` là mục
     * VNEDICT thật, nên span hai token vẫn được thử trước và thắng. Bản trước cắt
     * hư từ ngay từ đầu, tức là vĩnh viễn không tra được hai từ đó.
     */
    private const LEADING_FUNCTION_WORDS = [
        'con', 'cái', 'chiếc', 'cây', 'quả', 'trái', 'bức', 'tấm',
        'cuốn', 'quyển', 'ngôi', 'căn', 'người', 'sự', 'việc', 'cuộc', 'xin',
    ];

    /**
     * Một dạng không dấu khớp nhiều hơn ngần này term khác nhau thì nó không
     * mang tín hiệu gì.
     *
     * `cua` khớp `cúa`/`cưa`/`của`/`cứa`/`cửa`/`cựa` — gộp nghĩa của cả sáu cho
     * ra "palate, saw, amputate, property, possessions, belonging", sáu từ khóa
     * không liên quan gì nhau. `hoc tap` thì chỉ khớp đúng `học tập`, và đó là
     * ca mà đường không dấu tồn tại để phục vụ.
     */
    private const MAX_AMBIGUOUS_TERMS = 3;

    private const TTL_HIT = 86400;

    /**
     * Miss có TTL ngắn hơn nhiều vì đó là thứ người dùng sinh ra vô hạn: search
     * gõ tới đâu bắn tới đó, mỗi mốc debounce là một chuỗi chưa từng thấy. Cho
     * chúng TTL 24 giờ là tự nhận về một key-space không giới hạn.
     */
    private const TTL_MISS = 600;

    /**
     * Hư từ tiếng Anh — gỡ khỏi mỗi nghĩa TRƯỚC khi dựng tsquery.
     *
     * Đây là quy tắc quan trọng nhất của cả service, và lý do đo được: 14.479 /
     * 92.874 nghĩa trong VNEDICT bắt đầu bằng `to `. `search_tsv` dùng cấu hình
     * `simple` nên KHÔNG có stopword list — `to` là lexeme đầy đủ, có mặt trong
     * 32.506 / 123.646 dòng, `of` trong 23.624 dòng.
     *
     * Để nguyên `to thank` rồi hỏi Postgres tìm cụm đó, nó buộc phải kéo posting
     * list của `to` và GIN index bị bỏ qua — đo được `đến` cho seq scan 33.398
     * dòng, 97,9ms, spill 13MB. Gỡ hư từ xong thì `to thank` thành `thank`, một
     * lexeme chọn lọc, và index làm đúng việc của nó.
     */
    private const ENGLISH_STOPWORDS = [
        'to', 'of', 'a', 'an', 'the', 'and', 'or', 'in', 'at', 'on', 'for',
        'with', 'by', 'be', 'is', 'are', 'was', 'were', 'from', 'as', 'into',
    ];

    public function __construct(private readonly PinyinNormalizer $pinyin) {}

    /**
     * @return list<string>
     */
    public function resolve(string $query): array
    {
        $normalized = $this->normalize($query);

        if (mb_strlen($normalized) < self::MIN_LENGTH) {
            return [];
        }

        $key = "vi_bridge:v{$this->lexiconVersion()}:".sha1($normalized);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $terms = $this->lookup($normalized);

        Cache::put($key, $terms, $terms === [] ? self::TTL_MISS : self::TTL_HIT);

        return $terms;
    }

    /**
     * Version của lexicon, dùng làm tiền tố key cache.
     *
     * Lấy từ `MAX(updated_at)` của chính bảng lexicon, KHÔNG phải một bộ đếm
     * trong cache. Bộ đếm là thứ sai: nó sống trong cùng keyspace `allkeys-lru`
     * mà nó phục vụ, và nếu bị đuổi thì `resolve()` rơi về 0, lần import sau
     * đếm lại từ 1, rồi phục vụ tiếp các key `vi_bridge:v1:*` sinh ra từ lexicon
     * CŨ cho tới hết TTL 24 giờ — đúng cái hỏng mà version tồn tại để chặn.
     *
     * Nguồn này thì bị đuổi cũng không sao: tính lại từ database luôn ra đúng
     * giá trị, và nó tăng đơn điệu theo mỗi lần import.
     */
    private function lexiconVersion(): int
    {
        return (int) Cache::remember(
            'vi_lexicon:version',
            60,
            fn (): int => (int) strtotime((string) ViEnLexiconEntry::max('updated_at'))
        );
    }

    /**
     * Xóa kết quả cache của một truy vấn.
     *
     * Dành cho benchmark đo đường lạnh. Có mặt để lệnh đó không phải
     * `Cache::flush()` — flush xóa cả cache thống kê, phân tích Hán tự, và số
     * dòng của health, trên một lệnh mà runbook production đặt ngay cạnh
     * `vi-lexicon:status`.
     */
    public function forget(string $query): void
    {
        $normalized = $this->normalize($query);

        Cache::forget("vi_bridge:v{$this->lexiconVersion()}:".sha1($normalized));
    }

    /**
     * @return list<string>
     */
    private function lookup(string $normalized): array
    {
        $leadsWithFunctionWord = false;
        $spans = $this->spans($normalized, $leadsWithFunctionWord);

        if ($spans === []) {
            return [];
        }

        $plain = array_map(fn (string $s): string => $this->pinyin->stripDiacritics($s), $spans);

        /*
         * MỘT truy vấn cho mọi span, không phải một truy vấn mỗi ứng viên.
         *
         * `ORDER BY` tường minh vì kết quả bị đóng băng vào cache 24 giờ: không
         * có nó, Postgres trả theo thứ tự vật lý và cùng một dữ liệu cho ra kết
         * quả khác nhau ở những ngày khác nhau.
         */
        $rows = ViEnLexiconEntry::query()
            ->whereIn('term', $spans)
            ->orWhereIn('term_plain', $plain)
            ->orderBy('term')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Span DÀI nhất thắng: `máy tính` là một từ, không phải `máy` + `tính`.
        foreach ($spans as $index => $span) {
            $exact = $rows->where('term', $span);
            $loose = $rows->where('term_plain', $plain[$index]);

            /*
             * Sau khi bỏ loại từ, dạng KHÔNG DẤU không được để khớp chính xác
             * chặn đường gộp đồng âm.
             *
             * `con meo`: bỏ `con` còn `meo`, mà `meo` là một mục VNEDICT thật
             * ("moldy, perished"). Nếu khớp chính xác thắng ngay thì `mèo → cat`
             * không bao giờ được xét, và truy vấn trả về 霉, 发霉, 霉气 — không
             * có 猫. Đo được **557 nhóm** `term_plain` có một mục mà
             * `term = term_plain` che hết các mục có dấu như vậy.
             *
             * Loại từ CHÍNH LÀ tín hiệu phân biệt: ai viết `con meo` thì đang
             * nói về một danh từ, không phải tính từ "mốc". Người gõ trần `meo`
             * mới thực sự có thể muốn "moldy" — ca đó vẫn đi đường khớp chính
             * xác bên dưới.
             */
            $mergeHomographs = $leadsWithFunctionWord && $span === $plain[$index];

            if ($mergeHomographs && $loose->isNotEmpty()) {
                return $this->keywords($loose->pluck('senses')->flatten()->all());
            }

            if ($exact->isNotEmpty()) {
                return $this->keywords($exact->pluck('senses')->flatten()->all());
            }

            if ($loose->isEmpty()) {
                continue;
            }

            // Quá nhiều đồng âm không dấu → gộp chỉ sinh nhiễu, bỏ span này.
            if ($loose->unique('term')->count() > self::MAX_AMBIGUOUS_TERMS) {
                continue;
            }

            return $this->keywords($loose->pluck('senses')->flatten()->all());
        }

        return [];
    }

    /**
     * Mọi span liên tiếp, DÀI TRƯỚC.
     *
     * `con mèo đen` → `con mèo đen`, `con mèo`, `mèo đen`, `con`, `mèo`, `đen`
     *
     * Bản thiết kế đầu chỉ bỏ dần token ĐẦU, với lý do "tiếng Việt đặt loại từ
     * trước danh từ chính". Đúng một nửa: tiếng Việt cũng đặt định ngữ SAU trung
     * tâm. `con mèo đen` sẽ trượt qua `mèo` rồi hạ cánh xuống `đen` và trả về mọi
     * từ nghĩa "black" — không có 猫. Trả kết quả sai tệ hơn không trả gì.
     *
     * @return list<string>
     */
    private function spans(string $normalized, bool &$leadsWithFunctionWord): array
    {
        $tokens = array_slice(explode(' ', $normalized), 0, self::MAX_TOKENS);
        $count = count($tokens);

        $leadsWithFunctionWord = $count > 1
            && in_array($tokens[0], self::LEADING_FUNCTION_WORDS, true);

        $spans = [];

        for ($length = $count; $length >= 1; $length--) {
            $group = [];

            for ($start = 0; $start + $length <= $count; $start++) {
                $span = implode(' ', array_slice($tokens, $start, $length));

                if (mb_strlen($span) >= self::MIN_LENGTH) {
                    $group[] = $span;
                }
            }

            /*
             * Trong cùng độ dài: hư từ xuống cuối, phần còn lại giữ trái sang
             * phải. `usort` của PHP không ổn định trước 8.0 nhưng ổn định từ 8.0,
             * và repo chạy 8.4 — nên thứ tự tương đối của các span không phải hư
             * từ được giữ nguyên.
             */
            usort($group, fn (string $a, string $b): int => $this->isFunctionWord($a) <=> $this->isFunctionWord($b));

            $spans = [...$spans, ...$group];
        }

        return array_values(array_unique($spans));
    }

    private function isFunctionWord(string $span): int
    {
        return in_array($span, self::LEADING_FUNCTION_WORDS, true) ? 1 : 0;
    }

    /**
     * Lọc nghĩa thô thành từ khóa dùng được.
     *
     * Ba quy tắc, tất cả là hằng số của service — vá nhiễu chỉ cần deploy, không
     * cần reimport 54k dòng. Đó chính là lý do Phase 1 lưu nghĩa THÔ.
     *
     * @param  list<string>  $senses
     * @return list<string>
     */
    private function keywords(array $senses): array
    {
        $keywords = [];

        foreach ($senses as $sense) {
            $words = array_values(array_filter(
                explode(' ', $sense),
                fn (string $w): bool => $w !== '' && ! in_array($w, self::ENGLISH_STOPWORDS, true)
            ));

            if ($words === [] || count($words) > self::MAX_WORDS_PER_SENSE) {
                continue;
            }

            $keyword = implode(' ', $words);

            if (! in_array($keyword, $keywords, true)) {
                $keywords[] = $keyword;
            }

            if (count($keywords) >= self::MAX_TERMS) {
                break;
            }
        }

        return $keywords;
    }

    private function normalize(string $query): string
    {
        $query = Normalizer::normalize(trim($query), Normalizer::FORM_C) ?: $query;
        $query = mb_strtolower($query);
        $query = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query);

        return trim((string) preg_replace('/\s+/u', ' ', $query));
    }
}
