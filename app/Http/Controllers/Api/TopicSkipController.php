<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\TopicSkipStoreRequest;
use App\Models\UserSkippedWord;
use App\Models\UserWord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Từ người dùng KHÔNG muốn gặp lại trong màn học chủ đề.
 */
final class TopicSkipController
{
    /**
     * Trần trả về.
     *
     * Không có trần thì sau nhiều tháng đây là một mảng vài nghìn số nguyên tải
     * mỗi lần vào màn học. Mới nhất trước: từ bỏ qua gần đây mới là từ dễ gặp
     * lại nhất trong lát cắt thông dụng mà màn học bốc.
     */
    private const LIMIT = 2000;

    /**
     * Danh sách `word_id` KHÔNG đưa vào phiên học nữa.
     *
     * HỢP của hai nguồn, và nguồn thứ hai mới là phần dễ bỏ sót: từ người dùng
     * đã lưu rồi TỰ XOÁ khỏi kho. `user_words` soft-delete, nên
     * `/vocabulary/ids` (chạy qua Eloquent) không trả chúng, và
     * `user_skipped_words` cũng không có chúng — kết quả là màn học đề nghị lại
     * đúng những từ người dùng vừa chủ động xoá.
     *
     * Xoá khỏi kho là tín hiệu từ chối RÕ HƠN cả nút "Đã biết rồi". Gộp ở đây
     * để app không cần biết trạng thái thứ ba tồn tại.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $skipped = UserSkippedWord::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->pluck('word_id');

        $removed = UserWord::onlyTrashed()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->pluck('word_id');

        $ids = $skipped->concat($removed)->unique()->values()->all();

        return response()->json(['data' => $ids])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(TopicSkipStoreRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $wordId = (int) $request->validated('word_id');

        $existing = UserSkippedWord::query()
            ->where('user_id', $userId)->where('word_id', $wordId)->first();

        if ($existing instanceof UserSkippedWord) {
            // 200, không 201 và không 409: bỏ qua một từ đã bỏ qua là thao tác
            // idempotent, không phải lỗi. Cùng quy ước `VocabularyController::store()`.
            return response()->json(['data' => ['word_id' => $wordId]], Response::HTTP_OK)
                ->header('Cache-Control', 'private, no-store');
        }

        UserSkippedWord::create(['user_id' => $userId, 'word_id' => $wordId]);

        return response()->json(['data' => ['word_id' => $wordId]], Response::HTTP_CREATED)
            ->header('Cache-Control', 'private, no-store');
    }
}
