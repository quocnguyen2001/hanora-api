<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Search;

use App\Models\DictionaryWord;
use App\Models\SearchQueryInterpretation;
use App\Services\Gemini\GeminiClient;
use Illuminate\Support\Facades\DB;

/**
 * Diễn giải một truy vấn thành danh sách mục từ, có cache vĩnh viễn.
 *
 * Trả về `list<int>` id — RỖNG là kết quả hợp lệ và có nghĩa "không diễn giải
 * được", không phải "lỗi". Caller không cần phân biệt: cả hai đều dẫn tới việc
 * dùng nguyên kết quả SQL.
 */
final class SearchInterpreter
{
    /** Dài hơn thế không phải truy vấn tra từ; cũng là trần của cột. */
    private const MAX_QUERY_LENGTH = 200;

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly InterpretPrompt $prompts,
    ) {}

    /**
     * @return list<int>|null
     */
    public function interpret(string $query, string $mode): ?array
    {
        $normalized = self::normalize($query);

        if ($normalized === '') {
            return [];
        }

        /*
         * Không cấu hình key = lớp AI TẮT, và đó là một trạng thái đã biết chứ
         * không phải sự cố. Phân biệt hai thứ này quan trọng ở tầng trên: nhánh
         * "hỏng" đặt `no-store`, nên gộp chúng lại sẽ khiến một cài đặt chạy
         * không key mất sạch cache HTTP của `/search` — trả giá cache cho một
         * tính năng thậm chí không bật.
         */
        if (! is_string(config('services.gemini.key')) || config('services.gemini.key') === '') {
            return [];
        }

        $cached = SearchQueryInterpretation::query()
            ->where('query_normalized', $normalized)
            ->where('mode', $mode)
            ->first();

        if ($cached !== null) {
            /*
             * Tăng bằng UPDATE nguyên tử, không phải đọc-cộng-ghi: hai request
             * cùng lúc cho một truy vấn hot sẽ mất một lượt đếm, và con số này
             * là thứ duy nhất trả lời được "cache trúng bao nhiêu phần trăm".
             *
             * Hệ quả cần biết: nhánh trúng cache VẪN GHI. `/search` vì thế không
             * phục vụ được từ read replica. Chấp nhận ở quy mô hiện tại; nếu sau
             * này cần replica thì dồn phép đếm sang một hàng đợi, đừng bỏ nó đi.
             */
            SearchQueryInterpretation::query()
                ->whereKey($cached->id)
                ->update(['hit_count' => DB::raw('hit_count + 1')]);

            return array_map(intval(...), $cached->word_ids);
        }

        ['prompt' => $prompt, 'schema' => $schema] = $this->prompts->for($normalized, $mode);

        $result = $this->gemini->generate(
            $prompt,
            $schema,
            (int) config('services.gemini.search_timeout', 6),
        );

        /*
         * KHÔNG cache khi gọi hỏng.
         *
         * Cache ở đây là vĩnh viễn, nên đóng băng một sự cố mạng 30 giây thành
         * "truy vấn này không có kết quả, mãi mãi" là cách hỏng tệ nhất mà lớp
         * này có thể tạo ra. Mảng rỗng do AI CHỦ ĐỘNG trả về thì cache được —
         * đó là một câu trả lời, không phải một lỗi.
         */
        if (! $result->successful) {
            return null;
        }

        $ids = $this->resolve($result->payload['words'] ?? []);

        /*
         * `upsert`, KHÔNG phải `create`.
         *
         * Hai người cùng gõ một truy vấn mới trong 3,5 giây mà lời gọi kéo dài
         * là chuyện bình thường, không phải ca hiếm: cả hai đều trượt cache, cả
         * hai đều gọi Gemini, rồi người về sau đâm vào unique index và ăn 500.
         * `ON CONFLICT DO UPDATE` biến cuộc đua đó thành "ai về sau thì ghi đè",
         * và hai kết quả đó vốn tương đương nhau.
         */
        SearchQueryInterpretation::query()->upsert(
            [[
                'query_normalized' => $normalized,
                'mode' => $mode,
                'word_ids' => json_encode($ids),
                'model' => (string) config('services.gemini.model'),
                'prompt_version' => InterpretPrompt::VERSION,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['query_normalized', 'mode'],
            ['word_ids', 'model', 'prompt_version', 'updated_at'],
        );

        return $ids;
    }

    /**
     * Tra ngược corpus, giữ đúng thứ tự AI trả về.
     *
     * Chữ AI trả mà corpus không có thì bị loại — đo trên lớp làm giàu, tỉ lệ đó
     * là 8-11%, và nó gồm cả những thứ không phải mục từ (`不但……而且……`) lẫn
     * những từ không tồn tại. Hiện chúng cho người đang học là tệ hơn thiếu.
     *
     * MỘT truy vấn `whereIn` cho cả danh sách. `whereIn` không giữ thứ tự nên
     * phải sắp lại theo mảng gốc: thứ tự đó CHÍNH LÀ xếp hạng của AI, và nó là
     * thứ duy nhất lớp này đóng góp so với SQL.
     *
     * @return list<int>
     */
    private function resolve(mixed $words): array
    {
        if (! is_array($words) || $words === []) {
            return [];
        }

        $wanted = array_values(array_unique(array_filter(
            array_map(fn ($w) => is_string($w) ? trim($w) : '', $words),
            fn (string $w): bool => $w !== '',
        )));

        if ($wanted === []) {
            return [];
        }

        /*
         * Một chữ giản thể có thể ứng với nhiều mục khác âm (行 hành/hàng), nên
         * gom theo `simplified` rồi lấy mục phổ biến nhất. Trả cả hai lên đầu
         * danh sách tìm kiếm sẽ đẩy các từ khác của AI xuống mà không thêm thông
         * tin gì cho người gõ một truy vấn tiếng Việt.
         */
        $rows = DictionaryWord::query()
            ->whereIn('simplified', $wanted)
            ->orderByRaw('frequency_rank ASC NULLS LAST')
            ->orderBy('id')
            ->get(['id', 'simplified']);

        $byWord = [];

        foreach ($rows as $row) {
            $byWord[$row->simplified] ??= (int) $row->id;
        }

        $ids = [];

        foreach ($wanted as $word) {
            if (isset($byWord[$word])) {
                $ids[] = $byWord[$word];
            }
        }

        return $ids;
    }

    private static function normalize(string $query): string
    {
        $normalized = mb_strtolower(trim($query));
        $normalized = (string) preg_replace('/\s+/u', ' ', $normalized);

        return mb_substr($normalized, 0, self::MAX_QUERY_LENGTH);
    }
}
