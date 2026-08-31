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
     * Cache dài được vì dữ liệu từ điển hoàn toàn tĩnh sau V1 — không còn trạng
     * thái dịch thay đổi theo thời gian. `public` an toàn vì response KHÔNG
     * chứa trường nào theo user.
     *
     * `mode` nằm trong query string nên mỗi mode có entry cache riêng — đúng,
     * vì `?q=xin chào&mode=vi` và `&mode=cn` là hai kết quả khác nhau thật.
     * Đổi lại, một truy vấn tra ở cả hai mode chiếm gấp đôi chỗ cache.
     */
    private const CACHE_SECONDS = 60 * 60 * 24;

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
         * Điều đó đúng cả với `refine`: bấm nút ở trang 3 vẫn không có gì để AI
         * đóng góp, nên `$page === 1` đứng ngoài chứ không nằm trong ngoặc.
         */
        if ($page === 1 && ($forced || SearchWeakness::isWeak($top->rank ?? null, $top->precision ?? null, $results->total()))) {
            $interpretation = $interpreter->interpret($request->searchTerm(), $request->mode() ?? 'auto');

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
             * Một sự cố Gemini 30 giây KHÔNG được phép bị CDN đóng băng thành 24
             * giờ kết quả kém. Nhánh hỏng luôn `no-store`.
             */
            $aiFailed ? 'no-store' : 'public, max-age='.self::CACHE_SECONDS,
        );
    }
}
