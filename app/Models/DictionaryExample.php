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
 * @property string|null $translation_vi
 * @property int|null $vi_version
 * @property int $vi_attempts
 * @property string|null $contributor
 * @property string $license
 * @property int $char_length
 * @property int $quality_score
 */
final class DictionaryExample extends Model
{
    /**
     * Số câu tối đa trả về cho một từ.
     *
     * Đủ để thấy ngữ cảnh, không biến màn chi tiết thành một bức tường chữ.
     *
     * Hằng số DÙNG CHUNG giữa word detail và endpoint dịch, và đó là điểm mấu
     * chốt chứ không phải gọn code: hai con số lệch nhau nghĩa là FE nhận bản
     * dịch cho câu nó không hiện, hoặc thiếu bản dịch cho câu nó có hiện.
     */
    public const MAX_PER_WORD = 3;

    /**
     * Trần số lần dịch hỏng trước khi bỏ hẳn một câu.
     *
     * Cùng con số và cùng lý do với `DictionaryWordIllustration::MAX_ATTEMPTS`:
     * không có trần thì một câu Gemini luôn từ chối sẽ được xếp job lại mỗi lần
     * có người mở từ đó.
     */
    public const MAX_ATTEMPTS = 3;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vi_version' => 'integer',
            'vi_attempts' => 'integer',
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
