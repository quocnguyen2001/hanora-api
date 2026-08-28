<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Một truy vấn đã được AI diễn giải thành danh sách mục từ.
 *
 * @property int $id
 * @property string $query_normalized
 * @property string $mode
 * @property list<int> $word_ids
 * @property string $model
 * @property int $prompt_version
 * @property int $hit_count
 */
final class SearchQueryInterpretation extends Model
{
    protected $fillable = [
        'query_normalized', 'mode', 'word_ids', 'model', 'prompt_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['word_ids' => 'array'];
    }
}
