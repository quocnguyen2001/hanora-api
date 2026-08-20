<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\DictionaryWord;
use App\Services\Dictionary\PinyinNormalizer;

/**
 * Chấm bài cho CẢ HAI mode — một nguồn chấm duy nhất.
 *
 * Server chấm, không phải client. Và logic chuẩn hóa dùng lại
 * `PinyinNormalizer` của P4 chứ không viết lại (R5): nếu tìm kiếm và chấm bài
 * chuẩn hóa lệch nhau thì người dùng gõ đúng vẫn bị chấm sai, và đó là loại bug
 * gần như không tìm ra được từ báo cáo của người dùng.
 */
final class AnswerGrader
{
    public const MODE_MCQ = 'mcq';

    public const MODE_TYPING = 'typing';

    public function __construct(private readonly PinyinNormalizer $pinyin) {}

    /**
     * Trắc nghiệm: so `word_id` người dùng chọn với `word_id` của từ đang hỏi.
     *
     * Không cần lưu state phiên — đây là điểm mấu chốt khiến MCQ chấm được mà
     * không cần bảng phiên hay Redis (red team C1).
     */
    public function gradeMcq(int $answerWordId, int $expectedWordId): bool
    {
        return $answerWordId === $expectedWordId;
    }

    /**
     * Mode gõ: chấp nhận chữ Hán (giản thể hoặc phồn thể) HOẶC pinyin (D3).
     *
     * Người học gõ được chữ Hán là mục tiêu, nhưng không phải ai cũng có bộ gõ
     * tiếng Trung trên máy — chấp nhận pinyin để mode này dùng được thật.
     */
    public function gradeTyping(string $answer, DictionaryWord $expected): bool
    {
        $answer = trim($answer);

        if ($answer === '') {
            return false;
        }

        $withoutSpaces = preg_replace('/\s+/u', '', $answer) ?? $answer;

        if ($withoutSpaces === $expected->simplified || $withoutSpaces === $expected->traditional) {
            return true;
        }

        $normalized = $this->pinyin->plain($answer);

        return $normalized !== '' && $normalized === $expected->pinyin_plain;
    }
}
