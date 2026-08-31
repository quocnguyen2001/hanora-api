<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Streak\StreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StreakController
{
    /**
     * Chuỗi ngày của người dùng hiện tại.
     *
     * KHÔNG `Cache::remember` như `/stats/summary`. Chuỗi phải nhích ngay khi
     * người dùng thêm từ thứ 5 — họ đang nhìn chip lúc đó, và một cửa sổ cache
     * 60 giây ở đây là đúng thứ khiến chip và màn Thống kê nói hai con số khác
     * nhau. (Đó cũng là lý do `streak_days` đã rời khỏi `/stats/summary`.)
     *
     * Lịch 30 ngày chỉ tính khi `?calendar=1`: chip nằm trên header nên endpoint
     * này được gọi ở MỌI màn, còn lịch thì chỉ màn `/streak` cần.
     */
    public function __invoke(Request $request, StreakService $streak): JsonResponse
    {
        $withCalendar = $request->boolean('calendar');

        // Dữ liệu theo user: không bao giờ được service worker cache.
        return response()->json(['data' => $streak->snapshot($request->user(), $withCalendar)])
            ->header('Cache-Control', 'private, no-store');
    }
}
