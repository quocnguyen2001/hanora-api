<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Nội dung làm giàu của một mục từ, do AI sinh.
 *
 * @property int $id
 * @property int $word_id
 * @property string $status
 * @property array<string, mixed>|null $payload
 * @property string|null $model
 * @property int $prompt_version
 * @property int $attempts
 * @property string|null $failed_reason
 * @property Carbon|null $generated_at
 */
final class DictionaryWordEnrichment extends Model
{
    /** Đang chờ job sinh nội dung. Trạng thái khởi tạo của mọi bản ghi. */
    public const STATUS_PENDING = 'pending';

    /** Có payload đã qua validate, dùng được. */
    public const STATUS_READY = 'ready';

    /** Đã thử và hỏng; `failed_reason` nói vì sao. */
    public const STATUS_FAILED = 'failed';

    /** Quá số này thì thôi, đừng gọi lại nữa. */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'word_id', 'status', 'payload', 'model', 'prompt_version',
        'failed_reason', 'generated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DictionaryWord, $this>
     */
    public function word(): BelongsTo
    {
        return $this->belongsTo(DictionaryWord::class, 'word_id');
    }
}
