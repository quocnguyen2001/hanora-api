<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ReviewSessionRequest;
use App\Http\Requests\SubmitAnswerRequest;
use App\Http\Resources\ReviewAnswerResource;
use App\Http\Resources\ReviewSessionResource;
use App\Models\ReviewLog;
use App\Models\ReviewSession;
use App\Models\UserWord;
use App\Services\Review\AnswerGrader;
use App\Services\Review\ReviewSessionManager;
use App\Services\Review\SrsScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class ReviewController
{
    /**
     * Mở một phiên ôn.
     *
     * `POST` chứ không `GET`: endpoint này GHI một dòng vào DB. Để `GET` là mời
     * prefetch của trình duyệt và service worker tạo phiên ma.
     */
    public function start(ReviewSessionRequest $request, ReviewSessionManager $manager): JsonResponse
    {
        $result = $manager->start(
            $request->user(),
            $request->mode(),
            $request->source(),
            $request->limitValue(),
        );

        /*
         * Không có thẻ nào: 200 với `session: null`, và KHÔNG bản ghi nào được
         * tạo.
         *
         * `empty_reason` phân biệt "không có từ nào" với "có từ nhưng không
         * dựng được câu trắc nghiệm từ chúng". Gộp hai trường hợp sẽ khiến app
         * báo "Chưa có từ nào bạn từng sai" trong khi trang Thống kê đang hiện
         * đúng những từ đó.
         */
        if ($result['session'] === null) {
            return $this->privateJson([
                'session' => null,
                'items' => [],
                'empty_reason' => $result['empty_reason'],
            ]);
        }

        return $this->privateJson([
            'session' => (new ReviewSessionResource($result['session']))->resolve(),
            'items' => $result['items'],
            'empty_reason' => null,
        ], Response::HTTP_CREATED);
    }

    public function answer(
        SubmitAnswerRequest $request,
        AnswerGrader $grader,
        SrsScheduler $scheduler,
        ReviewSessionManager $manager,
    ): JsonResponse {
        /*
         * KIỂM QUYỀN SỞ HỮU (red team H1).
         *
         * `user_word_id` do client gửi lên và endpoint này ghi vào `user_words`
         * + `review_logs`. Không kiểm thì bất kỳ tài khoản nào cũng nộp bài lên
         * bản ghi của người khác, phá lịch ôn của họ và làm bẩn thống kê của cả
         * hai. Resolve qua quan hệ của user hiện tại → 404, theo đúng quy ước
         * không tiết lộ của P11.
         */
        $userWord = UserWord::query()
            ->with('word')
            ->where('user_id', $request->user()->id)
            ->find($request->validated('user_word_id'));

        if (! $userWord instanceof UserWord) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // Cùng quy ước cho phiên: phiên của người khác là 404, không phải 403.
        $session = ReviewSession::query()
            ->where('user_id', $request->user()->id)
            ->find($request->validated('review_session_id'));

        if (! $session instanceof ReviewSession) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $mode = (string) $request->validated('mode');
        $answeredAt = CarbonImmutable::now();

        $isCorrect = $mode === AnswerGrader::MODE_MCQ
            ? $grader->gradeMcq((int) $request->validated('answer_word_id'), $userWord->word_id)
            : $grader->gradeTyping((string) $request->validated('answer'), $userWord->word);

        $result = DB::transaction(function () use (
            $userWord, $session, $mode, $isCorrect, $answeredAt, $scheduler, $manager, $request
        ): array {
            /*
             * KHOÁ DÒNG PHIÊN, rồi mới kiểm trạng thái và suy `is_retry`.
             *
             * Kiểm `isOpen()` ngoài transaction để lại một cửa sổ TOCTOU: lượt
             * nộp qua cửa → `finish()` chốt điểm trên N log → lượt nộp commit
             * log thứ N+1. `finish()` idempotent nên không bao giờ tính lại, và
             * phiên mang vĩnh viễn một điểm không khớp log của chính nó.
             *
             * Cùng khoá này cũng khiến `isRetry()` đáng tin: hai lượt nộp ĐỒNG
             * THỜI cho cùng một từ đều thấy 0 log và đều tự nhận là lượt đầu,
             * làm scheduler chạy hai lần và bộ đếm nhảy hai bậc.
             */
            $session = ReviewSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Phiên đã chốt: 409, KHÔNG phải 404.
             *
             * Phiên có thật và thuộc về họ — nói dối ở đây khiến app không phân
             * biệt được "phiên đã kết thúc" với "lỗi", và người dùng mất câu trả
             * lời mà không hiểu vì sao.
             */
            if (! $session->isOpen()) {
                abort(Response::HTTP_CONFLICT, 'Phiên ôn này đã kết thúc.');
            }

            // SERVER suy, không nhận từ client — xem `ReviewSessionManager::isRetry()`.
            $isRetry = $manager->isRetry($session, $userWord);

            $intervalBefore = $userWord->interval_days;
            $updates = [];

            /*
             * LỊCH và BỘ ĐẾM là HAI quyết định, không phải một.
             *
             * Trước đây cả hai nằm chung trong `if (! $isRetry)`. Khi thêm chế
             * độ ôn từ hay sai, luật "đúng trong phiên weak thì không kéo dài
             * lịch" mà áp lên cả khối sẽ đóng băng luôn `review_count` và
             * `correct_count` — và vì số lần sai được suy ra bằng hiệu hai cột
             * đó, từ đã thuộc lòng sẽ không bao giờ rời khỏi danh sách hay sai.
             */
            if ($manager->shouldSchedule($session, $isCorrect, $isRetry)) {
                $scheduled = $scheduler->schedule($userWord, $isCorrect, $answeredAt);

                $updates += [
                    'interval_days' => $scheduled['interval_days'],
                    'ease_factor' => $scheduled['ease_factor'],
                    'repetitions' => $scheduled['repetitions'],
                    'status' => $scheduled['status'],
                    'next_review_at' => $scheduled['next_review_at'],
                ];
            }

            if ($manager->shouldCount($isRetry)) {
                $updates += [
                    'last_reviewed_at' => $answeredAt,
                    'review_count' => $userWord->review_count + 1,
                    'correct_count' => $userWord->correct_count + ($isCorrect ? 1 : 0),
                ];
            }

            if ($updates !== []) {
                $userWord->update($updates);
            }

            // CÙNG transaction với việc ghi log: bộ đếm phiên và log không được
            // phép rời nhau.
            $manager->recordAnswer($session, $isCorrect, $isRetry);

            ReviewLog::create([
                'user_id' => $userWord->user_id,
                'user_word_id' => $userWord->id,
                'review_session_id' => $session->id,
                'mode' => $mode,
                'is_correct' => $isCorrect,
                'is_retry' => $isRetry,
                'answer_raw' => $mode === AnswerGrader::MODE_TYPING
                    ? (string) $request->validated('answer')
                    : (string) $request->validated('answer_word_id'),
                'interval_before' => $intervalBefore,
                'interval_after' => $userWord->interval_days,
                'answered_at' => $answeredAt,
            ]);

            return [
                'correct' => $isCorrect,
                'correct_answer' => [
                    'word_id' => $userWord->word->id,
                    'simplified' => $userWord->word->simplified,
                    'pinyin' => $userWord->word->pinyin,
                    'han_viet' => $userWord->word->han_viet,
                ],
                'next_review_at' => $userWord->next_review_at?->toIso8601String(),
                'status' => $userWord->status,
                'is_retry' => $isRetry,
                // Tiến độ mới nhất, để app không phải gọi thêm chỉ để vẽ thanh
                // tiến độ.
                'session' => (new ReviewSessionResource($session->fresh()))->resolve(),
            ];
        });

        return $this->privateJson($result);
    }

    /**
     * Chốt phiên. Idempotent — gọi lại trả nguyên kết quả cũ.
     *
     * Trả CÙNG hình dạng với `GET /reviews/sessions/{id}`: màn tổng kết và màn
     * chi tiết phiên đọc cùng một payload, nên không thể hiện hai con số "từ
     * sai" khác nhau cho cùng một phiên.
     */
    public function finish(Request $request, int $id, ReviewSessionManager $manager): JsonResponse
    {
        $session = ReviewSession::query()
            ->where('user_id', $request->user()->id)
            ->find($id);

        if (! $session instanceof ReviewSession) {
            abort(Response::HTTP_NOT_FOUND);
        }

        /*
         * Cùng khoá như `answer()`: không có nó, hai `finish()` đồng thời cùng
         * qua cửa `isOpen()` và cùng UPDATE, nên tính idempotent chỉ đúng khi
         * các lời gọi tuần tự.
         */
        DB::transaction(function () use ($session, $manager): void {
            $locked = ReviewSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $manager->finish($locked);
        });

        $answers = ReviewLog::query()
            ->with('userWord.word')
            ->where('review_session_id', $session->id)
            ->orderBy('answered_at')
            ->orderBy('id')
            ->get();

        return $this->privateJson([
            'session' => (new ReviewSessionResource($session->fresh()))->resolve(),
            'answers' => ReviewAnswerResource::collection($answers)->resolve(),
        ]);
    }

    /**
     * Dữ liệu theo user: KHÔNG bao giờ được service worker hay proxy cache —
     * đó chính là đường rò dữ liệu giữa hai tài khoản trên cùng một thiết bị.
     *
     * @param  array<string, mixed>  $data
     */
    private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json(['data' => $data], $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
