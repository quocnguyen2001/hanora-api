<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\DictionarySearchRequest;
use App\Http\Resources\WordSearchResultResource;
use App\Services\Dictionary\Search\ResultMerger;
use App\Services\Dictionary\Search\SearchInterpreter;
use App\Services\Dictionary\Search\SearchWeakness;
use App\Services\Dictionary\WordSearchService;
use Illuminate\Http\JsonResponse;

final class DictionarySearchController
{
    /**
     * Câu trả lời của AI là CHUNG KẾT nên cache dài được.
     *
     * Diễn giải được cache vĩnh viễn theo `(truy vấn, mode)` phía DB, nên tra
     * lại — kể cả bấm refine lại — cho ra đúng danh sách này. Không có gì đợi
     * để thay đổi nữa.
     *
     * `public` an toàn vì response KHÔNG chứa trường nào theo user.
     *
     * `mode` nằm trong query string nên mỗi mode có entry cache riêng — đúng,
     * vì `?q=xin chào&mode=vi` và `&mode=cn` là hai kết quả khác nhau thật.
     */
    private const CACHE_SECONDS_AI = 60 * 60 * 24;

    /**
     * Kết quả SQL thuần thì KHÔNG chung kết, và đây là chỗ đã từng sai.
     *
     * Trước khi có nút "Tìm lại bằng AI", cache 24 giờ đúng vì dữ liệu từ điển
     * tĩnh hoàn toàn. Cái nút đó giết tiền đề ấy: bất kỳ ai bấm nó cũng đổi câu
     * trả lời của truy vấn đó kể từ lúc ấy. Giữ nguyên 24 giờ nghĩa là người vừa
     * bấm nút tra lại vẫn thấy y hệt kết quả cũ — trình duyệt phục vụ từ cache
     * mà không thèm hỏi server, và tính năng trông như hỏng.
     *
     * 5 phút: đủ để hấp thụ nhịp gõ và tra lặp trong một phiên, đủ ngắn để một
     * lượt refine lan ra mà không ai kịp gọi nó là lỗi. Đường SQL vốn ~9ms nên
     * cái giá của việc bỏ cache dài ở đây là nhỏ.
     */
    private const CACHE_SECONDS_SQL = 60 * 5;

    public function __invoke(
        DictionarySearchRequest $request,
        WordSearchService $search,
        SearchInterpreter $interpreter,
        ResultMerger $merger,
    ): JsonResponse {
        $page = $request->page();

        ['results' => $results, 'hint' => $hint] = $search->search(
            $request->searchTerm(),
            $page,
            $request->mode(),
        );

        $words = $search->hydrate($results->items());

        /*
         * Lớp AI nằm Ở ĐÂY, không nằm trong `WordSearchService`.
         *
         * Service đó là SQL thuần và phải giữ nguyên như vậy: nó vừa là đường
         * lùi khi Gemini chết, vừa là thứ `dictionary:benchmark` đo. Nhét một
         * lời gọi mạng vào trong nó là làm hỏng cả hai vai trò cùng lúc.
         */
        $top = $results->items()[0] ?? null;
        $aiFailed = false;
        $source = 'sql';
        $translation = null;

        /*
         * `refine=ai` là đường vòng THỦ CÔNG quanh `SearchWeakness`, không phải
         * một ngưỡng mới.
         *
         * Cổng tự động chỉ hỏi AI khi SQL trông yếu, nên ca "SQL tự tin nhưng
         * sai" — `rank ≤ 4`, hoặc `rank 6` khớp gloss chính xác — không bao giờ
         * tới được AI. Theo đúng thứ `SearchWeakness` đã đo, KHÔNG có tín hiệu
         * cấu trúc nào nhận ra ca đó; người dùng là tín hiệu duy nhất. Nút báo
         * kết quả sai chính là đường đưa tín hiệu đó vào.
         *
         * Cổng tự động giữ nguyên: nó vẫn quyết cho mọi truy vấn không có ai bấm
         * nút phía sau.
         */
        $forced = $request->wantsAiRefine();

        /*
         * Chỉ trang 1. Xếp hạng của AI là khái niệm của trang đầu — nó trả tối
         * đa 10 từ, không có gì để đóng góp cho trang 3, và trả tiền cho mỗi
         * trang là trả tiền cho cùng một câu trả lời nhiều lần.
         *
         * Điều đó đúng cả với `refine` lẫn với việc đọc cache: cả hai đều không
         * có gì để đóng góp cho trang 3.
         */
        if ($page === 1) {
            /*
             * HỎI AI khi người dùng ép hoặc SQL yếu. Ngoài ra chỉ ĐỌC thứ đã hỏi
             * rồi — `cached()` không bao giờ gọi Gemini.
             *
             * `SearchWeakness` vì thế vẫn là thứ DUY NHẤT quyết định có tiêu tiền
             * hay không; nó chỉ thôi quyết định việc có được đọc hay không. Đó là
             * hai câu hỏi khác nhau, và gộp chúng lại chính là lý do một truy vấn
             * đã được sửa đúng vẫn trả về kết quả sai ở lần tra kế tiếp.
             */
            $interpretation = $forced || SearchWeakness::isWeak($top->rank ?? null, $top->precision ?? null, $results->total())
                ? $interpreter->interpret($request->searchTerm(), $request->mode() ?? 'auto')
                : $interpreter->cached($request->searchTerm(), $request->mode() ?? 'auto');

            if ($interpretation->failed) {
                $aiFailed = true;
            } elseif (! $interpretation->isEmpty()) {
                $words = $merger->merge($interpretation->ids, $words);
                $translation = $interpretation->translation;
                $source = 'ai';
            }
        }

        return response()->json([
            'data' => WordSearchResultResource::collection($words)->resolve(),
            'meta' => [
                'page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                /*
                 * `max`, không phải tổng. Khi SQL trả 0 dòng mà AI tìm được 5
                 * từ, báo `total: 0` kèm 5 phần tử là tự mâu thuẫn. Cộng dồn thì
                 * lại đếm trùng những từ AI đề xuất vốn đã nằm sâu trong tập
                 * SQL. `max` không bao giờ nói sai về thứ đang hiển thị.
                 */
                'total' => max($results->total(), count($words)),
                /*
                 * Dập `hv_not_found` khi AI đã trả lời được.
                 *
                 * Hint đó khuyên người dùng "thử chuyển sang 中文" vì không khớp
                 * âm Hán-Việt nào. Hiện nó cạnh một danh sách kết quả đúng là
                 * đổ lỗi cho người dùng về một việc hệ thống vừa làm xong.
                 */
                'hint' => $source === 'ai' ? null : $hint,
                // FE và test dùng để biết đường nào đã chạy.
                'source' => $source,
            ],
            /*
             * Câu dịch nằm NGOÀI `data`, không phải phần tử đầu của nó.
             *
             * `data[]` là mục từ điển có `id` thật: lưu được vào sổ từ vựng, mở
             * được màn chi tiết. Một câu dịch không có id và không bao giờ là
             * mục từ điển — nhét nó vào cùng mảng là buộc FE đoán xem phần tử
             * nào bấm được, và làm nút lưu hỏng ở phần tử đầu tiên.
             *
             * `null` là trạng thái thường gặp nhất: chỉ truy vấn dạng CÂU mới có.
             */
            'translation' => $translation === null ? null : $translation + ['source' => 'ai'],
        ])->header(
            'Cache-Control',
            /*
             * Ba nhánh, và độ dài cache đi theo mức CHUNG KẾT của câu trả lời:
             *
             * - hỏng   → `no-store`. Một sự cố Gemini 30 giây không được phép bị
             *            CDN đóng băng thành 24 giờ kết quả kém.
             * - `ai`   → 24 giờ. Diễn giải cache vĩnh viễn phía DB, không đổi nữa.
             * - `sql`  → 5 phút. Một lượt bấm "Tìm lại bằng AI" đổi câu trả lời
             *            này bất cứ lúc nào; cache dài ở đây khiến chính người
             *            vừa bấm nút tra lại vẫn thấy kết quả cũ.
             */
            match (true) {
                $aiFailed => 'no-store',
                $source === 'ai' => 'public, max-age='.self::CACHE_SECONDS_AI,
                default => 'public, max-age='.self::CACHE_SECONDS_SQL,
            },
        );
    }
}
