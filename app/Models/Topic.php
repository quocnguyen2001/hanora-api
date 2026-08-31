<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TopicFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Một chủ đề học từ vựng.
 *
 * `name`/`emoji` ở đây là BẢN CHIẾU của `TopicCatalog`, không phải nguồn —
 * `topics:import` đồng bộ chúng và `TopicResource` đọc từ hằng. Xem ghi chú
 * trong migration.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $emoji
 * @property int $sort_order
 * @property int|null $user_id
 * @property string $status
 * @property string|null $prompt_term
 * @property string|null $failed_reason
 */
final class Topic extends Model
{
    /** @use HasFactory<TopicFactory> */
    use HasFactory;

    /** Đã sinh xong, dùng được. Mọi chủ đề gốc luôn ở trạng thái này. */
    public const STATUS_READY = 'ready';

    /** Job đang chạy. App poll cho tới khi rời trạng thái này. */
    public const STATUS_GENERATING = 'generating';

    /**
     * Job hỏng.
     *
     * Trạng thái hạng nhất, không phải ca bên lề: không có nó thì một job chết
     * để lại chủ đề treo ở `generating` vĩnh viễn, app poll mãi, và người dùng
     * mất một suất trong trần 20 mà không hiểu vì sao.
     */
    public const STATUS_FAILED = 'failed';

    /** Trần số chủ đề tự tạo mỗi tài khoản. */
    public const MAX_PER_USER = 20;

    protected $guarded = [];

    /**
     * Chủ đề mà `$userId` được phép thấy: chủ đề gốc + chủ đề của chính họ.
     *
     * MỌI truy vấn `topics` phải đi qua đây. Đây là chỗ dễ hỏng nhất của tính
     * năng chủ đề tự tạo và nó hỏng IM LẶNG: quên scope một lần là bộ từ của
     * người này đọc được bởi người khác.
     *
     * @param  Builder<Topic>  $query
     * @return Builder<Topic>
     */
    public function scopeVisibleTo($query, int $userId)
    {
        return $query->where(function ($scoped) use ($userId): void {
            $scoped->whereNull('user_id')->orWhere('user_id', $userId);
        });
    }

    public function isCustom(): bool
    {
        return $this->user_id !== null;
    }
}
