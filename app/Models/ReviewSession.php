<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReviewSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Một phiên ôn tập.
 *
 * `answered_count`/`correct_count` là bộ đếm HIỂN THỊ TIẾN ĐỘ. Điểm chốt ở
 * `ReviewSessionManager::finish()` đếm lại trên `review_logs` — xem migration.
 *
 * @property int $id
 * @property int $user_id
 * @property string $mode
 * @property string $source
 * @property int $planned_count
 * @property int $answered_count
 * @property int $correct_count
 * @property int|null $score
 * @property string|null $grade
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
final class ReviewSession extends Model
{
    /** @use HasFactory<ReviewSessionFactory> */
    use HasFactory;

    /** Từ tới hạn theo lịch SRS. */
    public const SOURCE_DUE = 'due';

    /** Từ hay sai, bỏ qua lịch tới hạn. */
    public const SOURCE_WEAK = 'weak';

    /**
     * Nguồn duy nhất cho FormRequest, builder và test.
     *
     * @var list<string>
     */
    public const SOURCES = [self::SOURCE_DUE, self::SOURCE_WEAK];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'score' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ReviewLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ReviewLog::class);
    }

    /** Phiên đang mở — chưa chốt điểm. */
    public function isOpen(): bool
    {
        return $this->finished_at === null;
    }

    /**
     * Thời lượng phiên, tính chứ không lưu.
     *
     * Chiều `started -> finished`, KHÔNG ngược lại: Carbon 3 trả diff CÓ DẤU
     * theo chiều `$this -> $argument`, nên viết ngược sẽ cho ra số âm và lịch
     * sử hiện "-8 phút".
     */
    public function durationSeconds(): ?int
    {
        if ($this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }
}
