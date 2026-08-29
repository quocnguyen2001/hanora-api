<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\ReviewLog;
use App\Models\ReviewSession;
use App\Models\User;
use App\Models\UserWord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Vòng đời một phiên ôn: tạo, ghi nhận từng lượt, chốt điểm.
 *
 * Ba luật ở đây đắt hơn vẻ ngoài của chúng — xem comment tại từng chỗ:
 * `is_retry` do server suy, hai cờ SRS tách rời, và điểm chốt đếm lại trên log.
 */
final class ReviewSessionManager
{
    public function __construct(
        private readonly ReviewSessionBuilder $builder,
        private readonly ReviewScore $score,
    ) {}

    /**
     * Mở một phiên mới.
     *
     * `session` là `null` khi không có thẻ nào để ôn — khi đó `empty_reason`
     * nói vì sao, và KHÔNG bản ghi nào được tạo.
     *
     * @return array{
     *     session: ReviewSession|null,
     *     items: list<array<string, mixed>>,
     *     empty_reason: string|null
     * }
     */
    public function start(User $user, string $mode, string $source, int $limit): array
    {
        return DB::transaction(function () use ($user, $mode, $source, $limit): array {
            /*
             * TUẦN TỰ HOÁ theo user.
             *
             * Transaction KHÔNG phải cơ chế loại trừ lẫn nhau: ở READ COMMITTED
             * hai `start()` đồng thời đều thấy 0 phiên mở rồi đều INSERT. Chuyện
             * này không hiếm — bấm kép nút "Bắt đầu ôn" trên mạng chậm là đủ, và
             * hậu quả thường không phải hai phiên mà là phiên B chốt phiên A
             * trong khi app giữ id của A, rồi mọi lượt nộp sau đó trả 409.
             *
             * Advisory lock thay vì partial unique index: index biến race thành
             * lỗi 500 người dùng không hiểu, lock thì tuần tự hoá im lặng. Lock
             * tự nhả khi transaction kết thúc.
             */
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$user->id]);

            $this->finishStale($user);

            $built = $this->builder->build($user, $mode, $source, $limit);

            /*
             * KHÔNG tạo bản ghi khi không có thẻ nào.
             *
             * Mở trang ôn lúc chưa tới hạn từ nào là thao tác bình thường; để
             * lại một dòng "0 điểm · Cần ôn thêm" trong lịch sử mỗi lần như thế
             * sẽ đắp đầy trang Thống kê bằng những phiên người dùng chưa từng
             * làm, và làm họ thấy mình kém.
             */
            if ($built['items'] === []) {
                return ['session' => null, 'items' => [], 'empty_reason' => $built['empty_reason']];
            }

            $session = ReviewSession::create([
                'user_id' => $user->id,
                'mode' => $mode,
                'source' => $source,
                // Số thẻ THỰC SỰ phát ra, không phải `$limit`: mode trắc nghiệm
                // bỏ những mục không dựng đủ 4 lựa chọn.
                'planned_count' => count($built['items']),
                'started_at' => CarbonImmutable::now(),
            ]);

            return ['session' => $session, 'items' => $built['items'], 'empty_reason' => null];
        });
    }

    /**
     * Dọn phiên người dùng bỏ dở. Không cron, không job — chi phí là một truy
     * vấn mỗi lần bắt đầu ôn.
     */
    public function finishStale(User $user): void
    {
        $open = ReviewSession::query()
            ->where('user_id', $user->id)
            ->whereNull('finished_at')
            ->get();

        foreach ($open as $session) {
            /*
             * Phiên chưa trả lời câu nào thì XOÁ, không chốt điểm 0 cho nó.
             *
             * Không có log nào trỏ vào nó nên FK `restrictOnDelete` không cản;
             * nếu nó cản thì `answered_count` đã nói dối và ta muốn biết ngay
             * thay vì âm thầm ghi một phiên rác vào lịch sử.
             */
            if ($session->answered_count === 0) {
                $session->delete();

                continue;
            }

            // Chốt vào thời điểm lượt trả lời CUỐI, không phải bây giờ: người
            // dùng đã rời đi từ lâu, tính cả quãng vắng mặt vào thời lượng phiên
            // sẽ cho ra "phiên ôn 14 tiếng".
            $lastAnsweredAt = ReviewLog::query()
                ->where('review_session_id', $session->id)
                ->max('answered_at');

            $this->finish($session, $lastAnsweredAt === null
                ? $session->started_at->toImmutable()
                : CarbonImmutable::parse($lastAnsweredAt));
        }
    }

    /**
     * Lượt này có phải LÀM LẠI trong cùng phiên không — SERVER quyết.
     *
     * Trước đây cờ này do client gửi lên, và nó chi phối cả hình phạt SRS lẫn
     * mẫu số của điểm: gửi `is_retry: true` cho mọi câu sai và `false` cho mọi
     * câu đúng là được 100 điểm. "Server chấm điểm" khi đó chỉ là phép chia do
     * server thực hiện trên tử số và mẫu số do client chọn.
     *
     * Bảng `review_sessions` chính là thứ cho phép suy nó: một `exists()` trên
     * index `(review_session_id, answered_at)`.
     */
    public function isRetry(ReviewSession $session, UserWord $userWord): bool
    {
        return ReviewLog::query()
            ->where('review_session_id', $session->id)
            ->where('user_word_id', $userWord->id)
            ->exists();
    }

    /**
     * Có cập nhật BỘ ĐẾM của từ không (`review_count`, `correct_count`,
     * `last_reviewed_at`) — và bộ đếm phiên.
     *
     * Luôn đúng cho lượt đầu, BẤT KỂ nguồn phiên. Xem `shouldSchedule()`.
     */
    public function shouldCount(bool $isRetry): bool
    {
        return ! $isRetry;
    }

    /**
     * Có chạy scheduler SRS không (`interval_days`, `ease_factor`,
     * `repetitions`, `status`, `next_review_at`).
     *
     * ## Vì sao TÁCH khỏi `shouldCount()`
     *
     * Trong phiên "từ hay sai", trả lời đúng KHÔNG được kéo dài lịch: những từ
     * đó thường chưa tới hạn, và nhân `interval × ease` vài lần là từ nhảy lên
     * `mastered` dù người dùng chưa nhớ nó.
     *
     * Nhưng nếu cùng cờ đó cũng khoá bộ đếm thì `review_count`/`correct_count`
     * đóng băng, và vì "số lần sai" được suy ra bằng `review_count -
     * correct_count`, từ đã thuộc lòng sẽ KHÔNG BAO GIỜ rời khỏi danh sách "từ
     * hay sai" — trả lời đúng 50 lần cũng vậy. Chế độ ôn từ sai khi đó là một
     * vòng lặp không lối ra, và `user_words` lệch vĩnh viễn với `review_logs`.
     *
     * Lượt làm lại thì không chạy cả hai: sai rồi sửa ngay mà vẫn tính sẽ xoá
     * sạch hình phạt SRS.
     */
    public function shouldSchedule(ReviewSession $session, bool $isCorrect, bool $isRetry): bool
    {
        if ($isRetry) {
            return false;
        }

        return ! ($session->source === ReviewSession::SOURCE_WEAK && $isCorrect);
    }

    /**
     * Nhích bộ đếm tiến độ của phiên.
     *
     * PHẢI gọi trong cùng transaction mà controller ghi `ReviewLog`. Dùng
     * `increment()` (UPDATE nguyên tử) chứ không đọc-rồi-ghi.
     *
     * Bộ đếm này phục vụ hiển thị tiến độ; `finish()` không tin nó.
     */
    public function recordAnswer(ReviewSession $session, bool $isCorrect, bool $isRetry): void
    {
        if (! $this->shouldCount($isRetry)) {
            return;
        }

        $session->increment('answered_count');

        if ($isCorrect) {
            $session->increment('correct_count');
        }
    }

    /**
     * Chốt điểm. Idempotent: phiên đã chốt trả về nguyên trạng.
     */
    public function finish(ReviewSession $session, ?CarbonImmutable $finishedAt = null): ReviewSession
    {
        if (! $session->isOpen()) {
            return $session;
        }

        /*
         * Đếm lại trên `review_logs`, KHÔNG dùng `answered_count`.
         *
         * Log là nguồn sự thật. Nếu tính từ bộ đếm: một `POST /answers` commit
         * ngay sau khi `finish()` đã chốt sẽ nhích bộ đếm lên, và vì `finish()`
         * idempotent nên phiên đó mang vĩnh viễn một `score` không khớp chính bộ
         * đếm của nó — sai lặng lẽ trên production trong khi test tuần tự vẫn
         * xanh.
         */
        $base = ReviewLog::query()
            ->where('review_session_id', $session->id)
            ->where('is_retry', false);

        $answered = (clone $base)->count();
        $correct = (clone $base)->where('is_correct', true)->count();

        $score = $this->score->score($answered, $correct);

        $session->update([
            'answered_count' => $answered,
            'correct_count' => $correct,
            'score' => $score,
            'grade' => $this->score->grade($score, $answered),
            'finished_at' => $finishedAt ?? CarbonImmutable::now(),
        ]);

        return $session;
    }
}
