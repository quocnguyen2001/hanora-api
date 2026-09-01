<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DictionaryWordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property list<array{simplified: string, traditional: string, pinyin: string}>|null $measure_words
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
     * `orderBy('id')` cuối cùng KHÔNG thừa. Hai câu bằng điểm và bằng độ dài là
     * chuyện thường, và không có khoá phá hoà thì Postgres được tự do trả thứ
     * tự khác nhau giữa hai truy vấn. Ở đó `limit(3)` của word detail và của
     * endpoint dịch có thể cắt ra HAI BỘ CÂU KHÁC NHAU — người dùng nhận bản
     * dịch cho câu màn hình không hiện.
     *
     * @return HasMany<DictionaryExample, $this>
     */
    public function examples(): HasMany
    {
        return $this->hasMany(DictionaryExample::class, 'word_id')
            ->orderByDesc('quality_score')
            ->orderBy('char_length')
            ->orderBy('id');
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
            /*
             * Cũng `null`-hợp-lệ như `definitions_vi`: phần lớn từ không có
             * lượng từ. Cast `array` giữ nguyên `null`, và tầng resource mới là
             * chỗ chuẩn hoá nó thành `[]` cho FE.
             */
            'measure_words' => 'array',
            'is_priority' => 'boolean',
            'is_single_char' => 'boolean',
            'hsk_level' => 'integer',
            'frequency_rank' => 'integer',
            'char_count' => 'integer',
        ];
    }

    /**
     * Nội dung làm giàu do AI sinh. `null` là trạng thái HỢP LỆ — phần lớn từ
     * chưa được sinh, và màn chi tiết phải render bình thường khi thiếu.
     *
     * @return HasOne<DictionaryWordEnrichment, $this>
     */
    public function enrichment(): HasOne
    {
        return $this->hasOne(DictionaryWordEnrichment::class, 'word_id');
    }

    /**
     * Ảnh minh hoạ, resolve từ Pixabay một lần rồi cache vĩnh viễn.
     *
     * Vắng mặt là trạng thái hợp lệ và phổ biến: cổng chặn cố tình từ chối hư
     * từ và từ trừu tượng, nên FE luôn phải dựng được màn chi tiết mà không có
     * quan hệ này.
     *
     * @return HasOne<DictionaryWordIllustration, $this>
     */
    public function illustration(): HasOne
    {
        return $this->hasOne(DictionaryWordIllustration::class, 'word_id');
    }
}
