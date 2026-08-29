<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\ReviewAnswerResource;
use App\Http\Resources\ReviewSessionResource;
use App\Http\Resources\WeakWordResource;
use App\Models\DictionaryWord;
use App\Models\ReviewLog;
use App\Models\ReviewSession;
use App\Models\User;
use App\Models\UserWord;
use App\Services\Review\WeakWordQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bốn endpoint CHỈ ĐỌC của phần lịch sử ôn tập.
 *
 * Tách khỏi `ReviewController` vì class đó sở hữu đường GHI (mở phiên, nộp bài,
 * chốt điểm). Nhồi bốn action đọc vào đó cho ra một class với hai trách nhiệm
 * và hai lý do để thay đổi.
 */
final class ReviewHistoryController
{
    private const PER_PAGE = 20;

    /** Số lượt gần nhất hiển thị ở màn chi tiết một từ. */
    private const RECENT_ANSWERS = 10;

    /**
     * Lịch sử phiên.
     *
     * Hai bộ lọc, không phải một: `finished_at` loại phiên ĐANG làm, còn
     * `answered_count > 0` là lưới an toàn kép bên cạnh việc `finishStale()` đã
     * xoá phiên rỗng. Nếu một đường nào đó vẫn để lọt phiên 0 lượt, lịch sử vẫn
     * sạch thay vì đầy những dòng "0 điểm · Cần ôn thêm".
     */
    public function sessions(Request $request): JsonResponse
    {
        $paginator = ReviewSession::query()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('finished_at')
            ->where('answered_count', '>', 0)
            /*
             * Keyset trên hai cột THẬT, khớp index `(user_id, started_at)`.
             *
             * Dùng được ở đây vì khoá sắp xếp BẤT BIẾN — khác hẳn `weakWords()`
             * bên dưới, nơi khoá là biểu thức và đổi mỗi lần người dùng trả lời.
             */
            ->orderBy('started_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $request->query('cursor'));

        return $this->privateJson(
            ReviewSessionResource::collection($paginator->items())->resolve(),
            [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        );
    }

    /**
     * Chi tiết một phiên: tổng kết + từng lượt trả lời.
     *
     * Cùng hình dạng với `POST /reviews/sessions/{id}/finish` — hai payload khác
     * nhau cho cùng thực thể sẽ cho ra hai con số "từ sai" trên hai màn.
     */
    public function session(Request $request, int $id): JsonResponse
    {
        $session = ReviewSession::query()
            ->where('user_id', $request->user()->id)
            ->find($id);

        if (! $session instanceof ReviewSession) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $answers = ReviewLog::query()
            ->with('userWord.word')
            ->where('review_session_id', $session->id)
            ->orderBy('answered_at')
            ->orderBy('id')
            ->get();

        return $this->privateJson([
            'session' => (new ReviewSessionResource($session))->resolve(),
            'answers' => ReviewAnswerResource::collection($answers)->resolve(),
        ]);
    }

    /**
     * Từ hay sai.
     *
     * Phân trang OFFSET, không cursor — xem `WeakWordQuery::query()` về lý do:
     * `cursorPaginate` lọc bỏ mọi order không có `direction`, và khoá sắp xếp ở
     * đây còn biến đổi mỗi lần người dùng trả lời.
     */
    public function weakWords(Request $request, WeakWordQuery $weakWords): JsonResponse
    {
        $paginator = $weakWords->query($request->user())
            ->with('word')
            ->paginate(self::PER_PAGE);

        /** @var list<UserWord> $items */
        $items = $paginator->items();
        $lastWrongAt = $this->lastWrongAtFor(
            $request->user(),
            array_map(static fn (UserWord $userWord): int => $userWord->id, $items),
        );

        return $this->privateJson(
            array_map(
                fn (UserWord $userWord): array => (new WeakWordResource($userWord, $lastWrongAt))->resolve(),
                $items,
            ),
            [
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        );
    }

    /**
     * Lịch sử ôn của MỘT từ.
     *
     * Khoá bằng dictionary word id, không phải `user_word_id`: app điều hướng
     * bằng `/words/:id`, nên khoá kia sẽ buộc màn chi tiết tra ngược một bảng
     * nữa chỉ để hỏi một câu về chính từ đang mở.
     */
    public function wordHistory(Request $request, DictionaryWord $word): JsonResponse
    {
        $userWord = UserWord::query()
            ->where('user_id', $request->user()->id)
            ->where('word_id', $word->id)
            ->first();

        // Chưa lưu từ này thì không có lịch sử để nói — 404, và app hiểu đó là
        // "không hiện mục lịch sử", không phải lỗi.
        if (! $userWord instanceof UserWord) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $recent = ReviewLog::query()
            ->with('userWord.word')
            ->where('user_word_id', $userWord->id)
            ->orderByDesc('answered_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_ANSWERS)
            ->get();

        $wrongCount = $userWord->review_count - $userWord->correct_count;

        return $this->privateJson([
            'user_word_id' => $userWord->id,
            'status' => $userWord->status,
            'review_count' => $userWord->review_count,
            'correct_count' => $userWord->correct_count,
            'wrong_count' => $wrongCount,
            'accuracy' => $userWord->review_count === 0
                ? 0
                : (int) round($userWord->correct_count / $userWord->review_count * 100),
            'last_wrong_at' => $this->lastWrongAtFor($request->user(), [$userWord->id])[$userWord->id] ?? null,
            'last_reviewed_at' => $userWord->last_reviewed_at?->toIso8601String(),
            'next_review_at' => $userWord->next_review_at?->toIso8601String(),
            'recent' => ReviewAnswerResource::collection($recent)->resolve(),
        ]);
    }

    /**
     * Mốc sai gần nhất cho nhiều từ — MỘT truy vấn cho cả trang.
     *
     * Hai điểm không được đổi:
     *
     * 1. `is_retry = false`. `wrong_count` hiển thị ngay cạnh con số này chỉ đếm
     *    lượt đầu, nên tính `last_wrong_at` trên cả lượt làm lại sẽ đặt hai số
     *    tính trên hai tập log khác nhau lên cùng một dòng — và một từ có
     *    `wrong_count = 0` vẫn có ngày "sai gần nhất", mâu thuẫn ngay trong một
     *    payload. Đây là bất biến chung của repo: lượt làm lại bị loại khỏi mọi
     *    số liệu tổng hợp, chỉ hiện trong danh sách lượt.
     * 2. Gộp một truy vấn, không phải mỗi từ một truy vấn. Chạy index
     *    `(user_word_id, answered_at)` có sẵn.
     *
     * @param  list<int>  $userWordIds
     * @return array<int, string>
     */
    private function lastWrongAtFor(User $user, array $userWordIds): array
    {
        if ($userWordIds === []) {
            return [];
        }

        return DB::table('review_logs')
            ->where('user_id', $user->id)
            ->where('is_correct', false)
            ->where('is_retry', false)
            ->whereIn('user_word_id', $userWordIds)
            ->groupBy('user_word_id')
            ->selectRaw('user_word_id, MAX(answered_at) AS last_wrong_at')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->user_word_id => Carbon::parse(
                    (string) $row->last_wrong_at
                )->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Dữ liệu theo user: KHÔNG bao giờ được service worker hay proxy cache.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<string, mixed>|null  $meta
     */
    private function privateJson(array $data, ?array $meta = null): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }
}
