<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Một Hán tự và các thuộc tính TẤT ĐỊNH của nó.
 *
 * Khác hẳn `DictionaryWordEnrichment`: ở đó nội dung do model sinh và có thể
 * sai; ở đây dữ liệu đến từ Make Me a Hanzi và không đổi. Không `status`, không
 * `attempts`, không `pending` — có thì trả, không có thì `null`.
 *
 * @property int $id
 * @property string $char
 * @property string|null $radical
 * @property string|null $radical_han_viet
 * @property int|null $stroke_count
 * @property string|null $decomposition
 * @property string|null $etymology_type
 * @property list<string>|null $stroke_names
 * @property list<string>|null $strokes
 * @property list<list<list<int>>>|null $medians
 */
final class DictionaryCharacter extends Model
{
    /**
     * Ba loại lục thư mà nguồn phân biệt, ánh xạ sang tiếng Việt.
     *
     * KHÔNG phải sáu. Hội ý, chuyển chú và giả tá không có trong Make Me a
     * Hanzi, nên không được suy ra. Chữ không rơi vào ba loại này thì
     * `etymology_type` là `null` và FE ẩn dòng.
     *
     * Hằng nằm ở model chứ không ở resource: cả FE lẫn lệnh CLI đều có thể cần
     * đọc nó, và hai bảng ánh xạ song song là hai chỗ để lệch nhau.
     */
    public const ETYMOLOGY_LABELS = [
        'pictophonetic' => 'hình thanh',
        'ideographic' => 'chỉ sự',
        'pictographic' => 'tượng hình',
    ];

    protected $fillable = [
        'char',
        'radical',
        'radical_han_viet',
        'stroke_count',
        'decomposition',
        'etymology_type',
        'stroke_names',
        'strokes',
        'medians',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stroke_count' => 'integer',
            'stroke_names' => 'array',
            'strokes' => 'array',
            'medians' => 'array',
        ];
    }
}
