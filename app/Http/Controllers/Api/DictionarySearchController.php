<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\DictionarySearchRequest;
use App\Http\Resources\WordSearchResultResource;
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

    public function __invoke(DictionarySearchRequest $request, WordSearchService $search): JsonResponse
    {
        ['results' => $results, 'hint' => $hint] = $search->search(
            $request->searchTerm(),
            $request->page(),
            $request->mode(),
        );

        $words = $search->hydrate($results->items());

        return response()->json([
            'data' => WordSearchResultResource::collection($words)->resolve(),
            'meta' => [
                'page' => $results->currentPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'hint' => $hint,
            ],
        ])->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }
}
