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
 * @property string $mode
 * @property bool $is_correct
 * @property bool $is_retry
 * @property string|null $answer_raw
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
     * @return BelongsTo<UserWord, $this>
     */
    public function userWord(): BelongsTo
    {
        return $this->belongsTo(UserWord::class);
    }
}
