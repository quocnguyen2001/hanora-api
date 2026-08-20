<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserWordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Một từ trong kho cá nhân của một người dùng.
 *
 * @property int $id
 * @property int $user_id
 * @property int $word_id
 * @property string $status
 * @property int $interval_days
 * @property float $ease_factor
 * @property int $repetitions
 * @property int $review_count
 * @property int $correct_count
 * @property Carbon|null $next_review_at
 * @property Carbon|null $last_reviewed_at
 * @property-read DictionaryWord $word
 */
final class UserWord extends Model
{
    /** @use HasFactory<UserWordFactory> */
    use HasFactory;

    use SoftDeletes;

    /** Chưa ôn lần nào. */
    public const STATUS_NEW = 'new';

    /** Đã ôn nhưng chưa vào nhịp ổn định. */
    public const STATUS_LEARNING = 'learning';

    /** Đang trong nhịp ôn giãn dần. */
    public const STATUS_REVIEWING = 'reviewing';

    /** `interval_days >= 30`. */
    public const STATUS_MASTERED = 'mastered';

    /**
     * Bảng map tab UI → status. Đây là NGUỒN DUY NHẤT cho P11, P12, P14, P16 —
     * ba chỗ đó từng lệch nhau (red team M2).
     *
     * Tab "Đang học" gộp `learning` và `reviewing`. Bỏ nó đi sẽ làm mọi từ đã ôn
     * một lần biến mất khỏi bộ lọc suốt tháng đầu, vì `mastered` cần
     * `interval_days >= 30`.
     *
     * @var array<string, list<string>>
     */
    public const TAB_STATUSES = [
        'new' => [self::STATUS_NEW],
        'learning' => [self::STATUS_LEARNING, self::STATUS_REVIEWING],
        'mastered' => [self::STATUS_MASTERED],
    ];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_review_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
            'ease_factor' => 'float',
        ];
    }

    /**
     * @return BelongsTo<DictionaryWord, $this>
     */
    public function word(): BelongsTo
    {
        return $this->belongsTo(DictionaryWord::class, 'word_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<UserWord>  $query
     * @return Builder<UserWord>
     */
    public function scopeForTab(Builder $query, ?string $tab): Builder
    {
        if ($tab === null || ! isset(self::TAB_STATUSES[$tab])) {
            return $query;
        }

        return $query->whereIn('status', self::TAB_STATUSES[$tab]);
    }
}
