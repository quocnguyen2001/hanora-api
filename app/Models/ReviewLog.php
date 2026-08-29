<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Nhật ký từng lượt trả lời.
 *
 * Giữ lại kể cả khi `user_words` đã soft-delete — xem migration.
 *
 * @property int $id
 * @property int $user_id
 * @property int $user_word_id
 * @property int|null $review_session_id
 * @property string $mode
 * @property bool $is_correct
 * @property bool $is_retry
 * @property string|null $answer_raw
 * @property int|null $duration_ms
 * @property int $interval_before
 * @property int $interval_after
 * @property Carbon $answered_at
 */
final class ReviewLog extends Model
{
    // Không có factory: log chỉ sinh ra qua đúng một đường — endpoint nộp bài.
    // Test dựng log bằng cách gọi endpoint đó, nên đường ghi thật cũng được kiểm.

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'is_retry' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * `withTrashed()` là BẮT BUỘC, không phải phòng xa.
     *
     * `user_words` dùng soft delete CHÍNH LÀ để log ở đây sống sót (xem
     * migration). Nhưng global scope của soft delete áp cả lên quan hệ
     * `belongsTo`, nên thiếu `withTrashed()` thì quan hệ trả `null` cho đúng
     * những log mà cơ chế kia được dựng ra để bảo vệ — và màn lịch sử ôn nổ 500
     * vĩnh viễn sau khi người dùng bỏ một từ khỏi kho.
     *
     * @return BelongsTo<UserWord, $this>
     */
    public function userWord(): BelongsTo
    {
        return $this->belongsTo(UserWord::class)->withTrashed();
    }

    /**
     * Phiên chứa lượt trả lời này.
     *
     * `null` với log sinh ra trước khi bảng `review_sessions` tồn tại — lịch sử
     * phiên vì vậy bắt đầu từ ngày triển khai, còn thống kê tổng vẫn tính trên
     * mọi log nên không có khoảng trống ở màn Thống kê.
     *
     * @return BelongsTo<ReviewSession, $this>
     */
    public function reviewSession(): BelongsTo
    {
        return $this->belongsTo(ReviewSession::class);
    }
}
