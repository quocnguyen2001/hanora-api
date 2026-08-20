<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\VocabularyIndexRequest;
use App\Http\Requests\VocabularyStoreRequest;
use App\Http\Resources\UserWordResource;
use App\Models\UserWord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class VocabularyController
{
    private const PER_PAGE = 20;

    public function index(VocabularyIndexRequest $request): JsonResponse
    {
        $query = UserWord::query()
            ->with('word')
            ->where('user_id', $request->user()->id)
            ->forTab($request->validated('status'));

        $this->applySearch($query, (string) ($request->validated('q') ?? ''));

        /*
         * KEYSET pagination, không phải offset.
         *
         * Người dùng vừa cuộn vừa lưu thêm từ là chuyện bình thường, và mỗi lần
         * chèn/xóa sẽ dịch biên trang của offset — `fetchNextPage` trả lại từ đã
         * hiện hoặc bỏ sót từ. Cursor trên `(created_at, id)` không bị ảnh hưởng.
         */
        $paginator = $query
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $request->validated('cursor'));

        return response()->json([
            'data' => UserWordResource::collection($paginator->items())->resolve(),
            'meta' => [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Danh sách `word_id` đã lưu.
     *
     * Đây là mảnh ghép thay cho `saved`/`user_word_id` đã gỡ khỏi response từ
     * điển ở P6 (red team C2). FE tải một lần, cache trong query client, và suy
     * ra trạng thái đã lưu cho cả word detail lẫn từng card ở màn tìm kiếm.
     */
    public function ids(Request $request): JsonResponse
    {
        $ids = UserWord::query()
            ->where('user_id', $request->user()->id)
            ->pluck('word_id')
            ->map(fn (int $id): int => $id)
            ->all();

        // `private, no-store`: đây là dữ liệu theo user và TUYỆT ĐỐI không được
        // service worker cache — đó chính là đường rò dữ liệu giữa hai tài khoản
        // trên cùng một thiết bị.
        return response()->json(['data' => $ids])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(VocabularyStoreRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $wordId = (int) $request->validated('word_id');

        // `withTrashed`: xóa rồi lưu lại cùng một từ phải KHÔI PHỤC bản ghi cũ,
        // giữ nguyên lịch sử ôn tập, chứ không tạo bản ghi trắng.
        $userWord = UserWord::withTrashed()
            ->where('user_id', $userId)
            ->where('word_id', $wordId)
            ->first();

        if ($userWord instanceof UserWord) {
            $restored = $userWord->trashed();

            if ($restored) {
                $userWord->restore();
            }

            return response()->json(
                ['data' => new UserWordResource($userWord->load('word'))],
                // 200 chứ không 201: không có bản ghi mới nào được tạo. Lưu lại
                // một từ đã lưu là thao tác idempotent, không phải lỗi.
                Response::HTTP_OK
            )->header('Cache-Control', 'private, no-store');
        }

        $userWord = UserWord::create([
            'user_id' => $userId,
            'word_id' => $wordId,
            'status' => UserWord::STATUS_NEW,
        ]);

        return response()->json(
            ['data' => new UserWordResource($userWord->load('word'))],
            Response::HTTP_CREATED
        )->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, int $id): Response
    {
        $userWord = UserWord::query()->find($id);

        /*
         * 404 chứ không 403 cho bản ghi của người khác.
         *
         * 403 xác nhận rằng id đó tồn tại, tức là biến endpoint này thành công
         * cụ dò xem người khác đã lưu bao nhiêu từ.
         */
        if (! $userWord instanceof UserWord || $userWord->user_id !== $request->user()->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $userWord->delete();

        return response()->noContent();
    }

    /**
     * Tìm trong kho: chữ Hán, pinyin, âm Hán-Việt, và định nghĩa tiếng Anh.
     *
     * @param  Builder<UserWord>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->whereHas('word', function ($wordQuery) use ($like, $term): void {
            $wordQuery
                ->where('simplified', 'like', $like)
                ->orWhere('traditional', 'like', $like)
                ->orWhere('pinyin_plain', 'like', $like)
                // `f_unaccent` để `hoc tap` khớp `học tập` — cùng wrapper mà P4
                // dùng khi sinh cột, nếu không index sẽ không được dùng.
                ->orWhereRaw('han_viet_plain LIKE \'%\' || f_unaccent(?) || \'%\'', [$term])
                ->orWhere('definitions_en_text', 'ilike', $like);
        });
    }
}
