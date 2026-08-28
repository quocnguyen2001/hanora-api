<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\DictionarySentenceRequest;
use App\Services\Dictionary\Sentence\SentenceAnalyzer;
use Illuminate\Http\JsonResponse;

/**
 * Chi tiết một CÂU tiếng Trung.
 *
 * Người dùng tới đây bằng cách bấm vào thẻ dịch ở màn tìm kiếm. Câu không phải
 * mục từ điển nên không có `id`; khoá là chính chuỗi Hán, truyền qua query
 * string. Nhờ vậy FE điều hướng được NGAY khi bấm, không phải gọi API để lấy
 * id rồi mới chuyển trang.
 *
 * **Không bao giờ 5xx vì Gemini** — cùng hợp đồng với `/search` và `/enrichment`.
 * Phân tích hỏng thì trả 200 kèm `data: null`, FE hiện trạng thái lỗi có nút thử
 * lại thay vì một màn hình vỡ.
 */
final class DictionarySentenceController
{
    /*
     * Câu đã phân tích là dữ liệu tĩnh vĩnh viễn — cùng câu, cùng kết quả, mãi
     * mãi. Cache dài như các endpoint từ điển khác; response KHÔNG chứa trường
     * nào theo user.
     */
    private const CACHE_SECONDS = 60 * 60 * 24;

    public function __invoke(DictionarySentenceRequest $request, SentenceAnalyzer $analyzer): JsonResponse
    {
        $payload = $analyzer->analyze($request->sentence());

        if ($payload === null) {
            return response()
                ->json(['data' => null, 'meta' => ['status' => 'unavailable']])
                // Hỏng là trạng thái TẠM THỜI. Để CDN đóng băng nó 24 giờ thì
                // một sự cố 30 giây biến thành một ngày câu đó không tra được.
                ->header('Cache-Control', 'no-store');
        }

        return response()
            ->json([
                'data' => $payload + ['source' => 'ai'],
                'meta' => ['status' => 'ready'],
            ])
            ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }
}
