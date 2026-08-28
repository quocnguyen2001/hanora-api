<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Sentence;

/**
 * Phân tích một câu tiếng Trung cho người Việt đang học.
 *
 * Xin ba thứ mà corpus KHÔNG có: tách từ theo ranh giới thật (chữ Hán không
 * cách nhau bằng khoảng trắng), nghĩa đen để đối chiếu với bản dịch thoát, và
 * ghi chú ngữ pháp.
 *
 * KHÔNG xin nghĩa của từng từ dài dòng: mỗi từ tách ra sẽ được tra ngược vào
 * `dictionary_words`, và nghĩa ở đó đã có nguồn. Model chỉ cần cho một nghĩa
 * NGẮN đúng trong ngữ cảnh câu này — thứ mà tra từ điển rời không cho được.
 */
final class SentencePrompt
{
    public const VERSION = 1;

    /** Quá số này thì không còn là câu để tra, mà là một đoạn văn. */
    private const MAX_TOKENS = 30;

    private const MAX_NOTES = 4;

    /**
     * @return array{prompt: string, schema: array<string, mixed>}
     */
    public function for(string $sentence): array
    {
        $maxTokens = self::MAX_TOKENS;
        $maxNotes = self::MAX_NOTES;

        $prompt = <<<PROMPT
        Phân tích câu tiếng Trung sau cho một người Việt đang học tiếng Trung:

        "{$sentence}"

        Trả về:

        `pinyin`: pinyin có dấu thanh cho cả câu, các chữ cách nhau bằng khoảng trắng.

        `vi`: bản dịch tiếng Việt TỰ NHIÊN, như người Việt sẽ nói.

        `literal_vi`: nghĩa ĐEN, dịch sát từng từ theo đúng thứ tự gốc. Đây là thứ
        cho người học thấy tiếng Trung sắp xếp ý khác tiếng Việt ở chỗ nào, nên
        đừng làm nó mượt — nếu nó nghe ngang thì đúng rồi.

        `tokens`: tách câu thành từng TỪ theo ranh giới từ thật, đúng thứ tự xuất
        hiện, tối đa {$maxTokens} phần tử.

        1. `zh` phải là một đoạn NGUYÊN VĂN cắt ra từ câu trên. Nối tất cả `zh`
           lại theo thứ tự phải ra đúng câu gốc, kể cả dấu câu.
        2. Tách theo TỪ chứ không theo từng chữ: 记得 là một token, không phải hai.
        3. `vi` của mỗi token là nghĩa NGẮN đúng trong ngữ cảnh câu này, 1-4 từ.
        4. Dấu câu là một token riêng, `vi` để chuỗi rỗng.

        `grammar_notes`: tối đa {$maxNotes} ghi chú ngắn về cấu trúc câu — trật tự
        từ, trợ từ, mẫu câu. Mỗi ghi chú một câu tiếng Việt. Chỉ ghi thứ người học
        Việt THẬT SỰ dễ sai; đừng giải thích thứ hiển nhiên.
        PROMPT;

        return [
            'prompt' => $prompt,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'pinyin' => ['type' => 'string'],
                    'vi' => ['type' => 'string'],
                    'literal_vi' => ['type' => 'string'],
                    'tokens' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'zh' => ['type' => 'string'],
                                'pinyin' => ['type' => 'string'],
                                'vi' => ['type' => 'string'],
                            ],
                            'required' => ['zh', 'pinyin', 'vi'],
                        ],
                    ],
                    'grammar_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['pinyin', 'vi', 'tokens'],
            ],
        ];
    }

    public static function maxTokens(): int
    {
        return self::MAX_TOKENS;
    }

    public static function maxNotes(): int
    {
        return self::MAX_NOTES;
    }
}
