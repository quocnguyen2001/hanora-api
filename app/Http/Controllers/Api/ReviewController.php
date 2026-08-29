<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\ReviewSessionRequest;
use App\Http\Requests\SubmitAnswerRequest;
use App\Models\ReviewLog;
use App\Models\ReviewSession;
use App\Models\UserWord;
use App\Services\Review\AnswerGrader;
use App\Services\Review\ReviewSessionBuilder;
use App\Services\Review\SrsScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class ReviewController
{
    public function session(ReviewSessionRequest $request, ReviewSessionBuilder $builder): JsonResponse
    {
        // `SOURCE_DUE` cố định: endpoint này bị thay bằng `POST /reviews/sessions`
        // ngay ở bước sau, nơi nguồn phiên trở thành tham số thật.
        $session = $builder->build(
            $request->user(),
            $request->mode(),
            ReviewSession::SOURCE_DUE,
            $request->limitValue(),
        );

        // Phiên ôn là dữ liệu theo user và thay đổi mỗi lần gọi.
        return response()->json(['data' => $session])
            ->header('Cache-Control', 'private, no-store');
    }

    public function answer(
        SubmitAnswerRequest $request,
        AnswerGrader $grader,
        SrsScheduler $scheduler,
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

        $mode = (string) $request->validated('mode');
        $isRetry = $request->isRetry();
        $answeredAt = CarbonImmutable::now();

        $isCorrect = $mode === AnswerGrader::MODE_MCQ
            ? $grader->gradeMcq((int) $request->validated('answer_word_id'), $userWord->word_id)
            : $grader->gradeTyping((string) $request->validated('answer'), $userWord->word);

        $result = DB::transaction(function () use (
            $userWord, $mode, $isCorrect, $isRetry, $answeredAt, $scheduler, $request
        ): array {
            $intervalBefore = $userWord->interval_days;

            /*
             * Lượt LÀM LẠI được ghi log nhưng KHÔNG chạy scheduler (H3).
             *
             * Nếu chạy: sai rồi sửa ngay sẽ cho ra cùng lịch như đúng ngay từ
             * đầu — `repetitions` đã về 0 nên lần đúng kế tiếp áp luật "lần 1" —
             * tức hình phạt SRS bị xóa sạch. Người dùng học được cách bấm bừa
             * rồi sửa.
             */
            if (! $isRetry) {
                $scheduled = $scheduler->schedule($userWord, $isCorrect, $answeredAt);

                $userWord->update([
                    'interval_days' => $scheduled['interval_days'],
                    'ease_factor' => $scheduled['ease_factor'],
                    'repetitions' => $scheduled['repetitions'],
                    'status' => $scheduled['status'],
                    'next_review_at' => $scheduled['next_review_at'],
                    'last_reviewed_at' => $answeredAt,
                    'review_count' => $userWord->review_count + 1,
                    'correct_count' => $userWord->correct_count + ($isCorrect ? 1 : 0),
                ]);
            }

            ReviewLog::create([
                'user_id' => $userWord->user_id,
                'user_word_id' => $userWord->id,
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
            ];
        });

        return response()->json(['data' => $result])
            ->header('Cache-Control', 'private, no-store');
    }
}
