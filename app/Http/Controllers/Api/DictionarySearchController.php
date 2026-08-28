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

        /*
         * Chỉ trang 1. Xếp hạng của AI là khái niệm của trang đầu — nó trả tối
         * đa 10 từ, không có gì để đóng góp cho trang 3, và trả tiền cho mỗi
         * trang là trả tiền cho cùng một câu trả lời nhiều lần.
         */
        if ($page === 1 && SearchWeakness::isWeak($top->rank ?? null, $top->precision ?? null, $results->total())) {
            $aiIds = $interpreter->interpret($request->searchTerm(), $request->mode() ?? 'auto');

            if ($aiIds === null) {
                $aiFailed = true;
            } elseif ($aiIds !== []) {
                $words = $merger->merge($aiIds, $words);
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
                'hint' => $hint,
                // FE và test dùng để biết đường nào đã chạy.
                'source' => $source,
            ],
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
