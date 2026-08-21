<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Một mục từ điển Việt-Anh dùng làm cầu nối tìm kiếm.
 *
 * Nguồn: VNEDICT (Paul Denisowski), CC BY 3.0.
 *
 * KHÔNG hiển thị ở đâu trong app. `senses` là danh sách nghĩa tiếng Anh THÔ,
 * chỉ dùng để dựng tsquery khớp với `dictionary_words.search_tsv`. Việc lọc
 * nhiễu thuộc về `VietnameseQueryBridge`.
 *
 * @property int $id
 * @property string $term
 * @property string $term_plain
 * @property list<string> $senses
 */
final class ViEnLexiconEntry extends Model
{
    protected $table = 'vi_en_lexicon';

    /** @var list<string> */
    protected $fillable = ['term', 'term_plain', 'senses'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['senses' => 'array'];
    }
}
