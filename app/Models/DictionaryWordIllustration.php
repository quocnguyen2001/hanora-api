<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ảnh minh hoạ của một mục từ, resolve từ Pixabay và cache vĩnh viễn.
 *
 * @property int $id
 * @property int $word_id
 * @property string $status
 * @property string|null $image_url
 * @property string|null $preview_url
 * @property string|null $page_url
 * @property string|null $author
 * @property string|null $author_url
 * @property int|null $source_id
 * @property int|null $width
 * @property int|null $height
 * @property string|null $matched_query
 * @property int $gate_version
 * @property int $attempts
 * @property string|null $failed_reason
 * @property Carbon|null $resolved_at
 */
final class DictionaryWordIllustration extends Model
{
    /** Đang chờ job resolve. Trạng thái khởi tạo của mọi bản ghi. */
    public const STATUS_PENDING = 'pending';

    /** Có ảnh dùng được. */
    public const STATUS_READY = 'ready';

    /**
     * Cổng chặn đã đóng: từ này KHÔNG nên có ảnh.
     *
     * Đây là kết quả THÀNH CÔNG và vĩnh viễn, không phải một kiểu thất bại.
     * Hư từ (`的`) và từ trừu tượng (`可能`) rơi vào đây, và chúng không bao giờ
     * được resolve lại chừng nào `gate_version` chưa đổi.
     */
    public const STATUS_NONE = 'none';

    /** Đã thử và hỏng; `failed_reason` nói vì sao. */
    public const STATUS_FAILED = 'failed';

    /** Quá số này thì thôi, đừng gọi lại nữa. */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'word_id', 'status', 'image_url', 'preview_url', 'page_url',
        'author', 'author_url', 'source_id', 'width', 'height',
        'matched_query', 'gate_version', 'failed_reason', 'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'source_id' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'gate_version' => 'integer',
            'attempts' => 'integer',
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
