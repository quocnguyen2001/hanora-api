<?php

declare(strict_types=1);

namespace App\Services\Topic;

/**
 * Bộ từ đã sinh cho một chủ đề, kèm đủ số liệu để người rà quyết định.
 *
 * `rejections` và `roundStats` KHÔNG phải số liệu trang trí: chúng là thứ duy
 * nhất phân biệt "chủ đề này nghèo từ" với "prompt hỏng" với "từ điển thiếu dữ
 * liệu" — ba nguyên nhân đòi ba hành động khác nhau.
 */
final readonly class TopicGenerationResult
{
    /**
     * @param  list<array{zh: string, pinyin: string, rank: int, batch: int, vi: string, rejected?: list<array{pinyin: string, vi: string}>}>  $words
     * @param  array<string, int>  $rejections  khoá là `TopicRejection::value`
     * @param  list<array{round: int, returned: int, accepted: int, duplicateRatio: float}>  $roundStats
     */
    public function __construct(
        public string $slug,
        public TopicGenerationOutcome $outcome,
        public array $words,
        public array $rejections,
        public array $roundStats,
        public ?string $failureReason = null,
    ) {}

    /**
     * Bộ từ RỖNG không được ghi, dù kết cục là `Exhausted`.
     *
     * Gemini trả `{"items": []}` đúng schema là một vòng "thành công" cho 0 từ;
     * tỉ lệ trùng khi đó tính ra 1.0 → vượt ngưỡng → dừng → `Exhausted`. Không
     * có chốt này thì lệnh ghi ra `{"words": []}` và exit 0 — đúng hình dạng
     * "file cụt trông hợp lệ" mà enum kết cục tồn tại để chặn, chỉ là đi vào
     * bằng cửa thành công.
     */
    public function isWritable(): bool
    {
        return $this->outcome === TopicGenerationOutcome::Exhausted && $this->words !== [];
    }

    public function count(): int
    {
        return count($this->words);
    }
}
