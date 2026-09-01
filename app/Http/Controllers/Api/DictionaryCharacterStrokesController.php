<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\DictionaryCharacter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hình học nét của một chữ, cho bảng tập viết.
 *
 * Tách khỏi metadata Hán tự có chủ đích. Metadata (bộ, số nét, hình thái, lục
 * thư, nét bút) nhẹ vài trăm byte và đi kèm `GET /words/{id}`; `strokes` +
 * `medians` nặng ~4 KB mỗi chữ và chỉ cần khi người dùng bấm "Tập viết Hán tự".
 * Gộp chung là bắt một từ 4 chữ kéo về 16 KB hình học cho một tính năng mà thiểu
 * số dùng, cộng thêm 4 request trên trần 60/phút theo user.
 *
 * KHÔNG có `pending` hay `unavailable`. Khác hẳn ảnh minh hoạ và lớp làm giàu:
 * ở đây không job nền, không Gemini, không hạn mức. Có thì 200, không thì 404 —
 * FE không cần vòng poll nào.
 */
final class DictionaryCharacterStrokesController
{
    /** Dữ liệu tất định và không bao giờ đổi, nên cache được tối đa. */
    private const CACHE_SECONDS = 31_536_000;

    public function __invoke(string $char): JsonResponse
    {
        $row = DictionaryCharacter::query()
            ->where('char', $char)
            ->whereNotNull('strokes')
            ->first(['char', 'strokes', 'medians']);

        if ($row === null) {
            // 404 chứ không `data: null`: khác lớp lười, "không có nét" ở đây
            // không phải một kết luận về nội dung mà là chữ nằm ngoài bộ dữ
            // liệu 9.574 chữ. FE hiện thông báo và đóng sheet.
            throw new NotFoundHttpException('Không có dữ liệu nét cho chữ này.');
        }

        return response()
            ->json(['data' => [
                'char' => $row->char,
                'strokes' => $row->strokes,
                'medians' => $row->medians,
            ]])
            ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS.', immutable');
    }
}
