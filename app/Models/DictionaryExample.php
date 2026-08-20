<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Câu ví dụ Tatoeba gắn với một mục từ điển.
 *
 * KHÔNG có pinyin cho câu, và đó là quyết định chứ không phải thiếu sót (D6).
 * Sinh pinyin cho cả câu cần tách từ tiếng Trung cộng phân giải chữ đa âm
 * (行 háng/xíng, 长 cháng/zhǎng) — một bài toán NLP riêng, không phải một lệnh
 * gọi `PinyinNormalizer` (H12).
 *
 * @property int $id
 * @property int $word_id
 * @property string $sentence_zh
 * @property string $translation_en
 * @property string|null $contributor
 * @property string $license
 * @property int $char_length
 * @property int $quality_score
 */
final class DictionaryExample extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<DictionaryWord, $this>
     */
    public function word(): BelongsTo
    {
        return $this->belongsTo(DictionaryWord::class, 'word_id');
    }
}
