<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DictionaryWordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một mục từ điển CC-CEDICT đã chuẩn hóa.
 *
 * Nội dung dùng chung cho mọi người dùng — KHÔNG có trường nào theo user.
 * Trạng thái "đã lưu" nằm ở `user_words` (P11) và lấy riêng qua
 * `GET /api/vocabulary/ids`, để response từ điển cache được lâu mà không rò rỉ
 * dữ liệu giữa các tài khoản (red team C2).
 *
 * @property int $id
 * @property string $simplified
 * @property string $traditional
 * @property string $pinyin
 * @property string $pinyin_numbered
 * @property string $pinyin_plain
 * @property list<string> $definitions_en
 * @property string $definitions_en_text
 * @property list<string>|null $definitions_vi
 * @property string|null $definitions_vi_text
 * @property string|null $han_viet
 * @property string|null $han_viet_plain
 * @property string $han_viet_status
 * @property int|null $hsk_level
 * @property int|null $frequency_rank
 * @property bool $is_priority
 * @property int $char_count
 * @property bool $is_single_char
 */
final class DictionaryWord extends Model
{
    /** @use HasFactory<DictionaryWordFactory> */
    use HasFactory;

    /** Trạng thái của `han_viet_status`, P5 sở hữu vòng đời này. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_OK = 'ok';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_MISSING = 'missing';

    /** Sửa tay qua artisan command (D10) — import KHÔNG được ghi đè. */
    public const STATUS_MANUAL = 'manual';

    protected $guarded = [];

    /**
     * Câu ví dụ, tốt nhất trước. P13 điền; rỗng là trạng thái hợp lệ và FE ẩn
     * hẳn section đó (D6).
     *
     * @return HasMany<DictionaryExample, $this>
     */
    public function examples(): HasMany
    {
        return $this->hasMany(DictionaryExample::class, 'word_id')
            ->orderByDesc('quality_score')
            ->orderBy('char_length');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definitions_en' => 'array',
            /*
             * `null` là trạng thái HỢP LỆ, không phải dữ liệu thiếu: ~7% mục
             * không khớp CVDICT và hiển thị bằng tiếng Anh như trước. Cast
             * `array` giữ nguyên `null`, nên FE phân biệt được "không có nghĩa
             * Việt" với "có nhưng rỗng".
             */
            'definitions_vi' => 'array',
            'is_priority' => 'boolean',
            'is_single_char' => 'boolean',
            'hsk_level' => 'integer',
            'frequency_rank' => 'integer',
            'char_count' => 'integer',
        ];
    }
}
