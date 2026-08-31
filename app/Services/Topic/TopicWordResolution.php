<?php

declare(strict_types=1);

namespace App\Services\Topic;

use App\Models\DictionaryWord;

/**
 * Kết quả phân giải một đề xuất: hoặc một mục từ điển, hoặc một lý do loại.
 *
 * Trả `null` kèm lý do thay vì ném exception: loại một từ là kết cục THƯỜNG
 * GẶP và hợp lệ — model đề xuất 40 từ thì mất vài từ là bình thường. Exception
 * ở đây sẽ biến luồng chính thành luồng lỗi.
 */
final readonly class TopicWordResolution
{
    private function __construct(
        public ?DictionaryWord $word,
        public ?TopicRejection $rejection,
    ) {}

    public static function accepted(DictionaryWord $word): self
    {
        return new self($word, null);
    }

    public static function rejected(TopicRejection $rejection): self
    {
        return new self(null, $rejection);
    }

    public function isAccepted(): bool
    {
        return $this->word instanceof DictionaryWord;
    }
}
