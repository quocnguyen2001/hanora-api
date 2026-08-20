<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\StatsSummaryRequest;
use App\Services\Stats\StatsSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

final class StatsController
{
    /**
     * Cache ngắn: thống kê không cần tức thời, nhưng phải đổi trong cùng phiên
     * học — người dùng ôn xong mở tab Thống kê phải thấy số đã nhúc nhích.
     */
    private const CACHE_SECONDS = 60;

    public function __invoke(StatsSummaryRequest $request, StatsSummaryService $stats): JsonResponse
    {
        $user = $request->user();
        $range = $request->range();

        $data = Cache::remember(
            "stats.summary.{$user->id}.{$range}",
            self::CACHE_SECONDS,
            fn (): array => $stats->summary($user, $range),
        );

        // Dữ liệu theo user: không bao giờ được service worker cache.
        return response()->json(['data' => $data])
            ->header('Cache-Control', 'private, no-store');
    }
}
