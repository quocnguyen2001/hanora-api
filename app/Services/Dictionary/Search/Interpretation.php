<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Search;

/**
 * Kết quả diễn giải một truy vấn.
 *
 * Hai phần TÁCH BIỆT, và sự tách biệt đó là bản chất chứ không phải cách đóng gói:
 *
 * - `ids` là mục từ điển có `id` thật, lưu được vào sổ từ vựng, tra được chi tiết.
 * - `translation` là một câu do AI dịch. Nó KHÔNG có id, KHÔNG lưu được, và
 *   không bao giờ tồn tại trong `dictionary_words`.
 *
 * `failed` phân biệt "AI nói không có gì" với "không gọi được AI" — hai thứ này
 * cần header cache ngược nhau.
 */
final readonly class Interpretation
{
    /**
     * @param  list<int>  $ids
     * @param  array{zh: string, pinyin: string, vi: string}|null  $translation
     */
    private function __construct(
        public array $ids,
        public ?array $translation,
        public bool $failed,
    ) {}

    /**
     * @param  list<int>  $ids
     * @param  array{zh: string, pinyin: string, vi: string}|null  $translation
     */
    public static function of(array $ids, ?array $translation = null): self
    {
        return new self($ids, $translation, false);
    }

    public static function failed(): self
    {
        return new self([], null, true);
    }

    public function isEmpty(): bool
    {
        return $this->ids === [] && $this->translation === null;
    }
}
