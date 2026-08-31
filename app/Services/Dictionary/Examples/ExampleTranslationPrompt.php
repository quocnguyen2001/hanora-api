<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Examples;

use App\Models\DictionaryExample;
use Illuminate\Support\Collection;

/**
 * Dịch sang tiếng Việt các câu ví dụ của MỘT từ.
 *
 * Lô = số câu của một từ (tối đa 3), KHÔNG gom nhiều từ như `GlossPrompt`. Job
 * bọc prompt này khoá theo `word_id` để chống dẫm chân; gom chéo từ sẽ phá đúng
 * cái khoá đó.
 *
 * Prompt neo vào CẢ câu Hán lẫn bản dịch tiếng Anh. Bản Anh là mỏ neo nghĩa —
 * nó là bản dịch có người viết, do Tatoeba xuất bản — còn câu Hán giữ model khỏi
 * trôi theo cách diễn đạt tiếng Anh. Bỏ một trong hai đều làm chất lượng tệ đi
 * theo hai kiểu khác nhau.
 */
final class ExampleTranslationPrompt
{
    /**
     * v1. Tăng khi prompt đổi tới mức bản dịch cũ không dùng được nữa; cột
     * `vi_version` cho phép sinh lại đúng phần đã cũ thay vì xoá sạch.
     */
    public const VERSION = 1;

    /**
     * Trần độ dài một bản dịch.
     *
     * Câu ví dụ Tatoeba tối đa vài chục ký tự Hán; một câu trả lời dài hơn thế
     * nhiều lần nghĩa là model đã kèm giải thích chứ không còn là bản dịch.
     */
    private const MAX_LENGTH = 400;

    /**
     * Đã có bản dịch sinh bởi phiên bản prompt HIỆN HÀNH chưa.
     *
     * Nằm ở đây chứ không ở model, cùng chỗ với `VERSION`: model không biết gì
     * về prompt, và 9 model còn lại của repo không phụ thuộc vào tầng service.
     *
     * Kiểm cả `vi_version` chứ không chỉ `translation_vi !== null`: bản dịch cũ
     * vẫn đọc được, nhưng nó không được tính là "xong" khi prompt đã đổi.
     */
    public static function isCurrent(DictionaryExample $example): bool
    {
        return $example->translation_vi !== null && $example->vi_version === self::VERSION;
    }

    /**
     * @param  Collection<int, DictionaryExample>  $examples
     * @return array{prompt: string, schema: array<string, mixed>}
     */
    public function for(Collection $examples): array
    {
        $lines = [];

        foreach ($examples->values() as $index => $example) {
            $lines[] = "[{$index}] {$example->sentence_zh}\n"
                ."    EN: {$example->translation_en}";
        }

        $items = implode("\n", $lines);

        $prompt = <<<PROMPT
        Bạn đang dịch câu ví dụ cho một ứng dụng học tiếng Trung dành cho người Việt.

        Với mỗi câu dưới đây, trả về bản dịch tiếng Việt tự nhiên.

        {$items}

        RÀNG BUỘC:
        1. `i` phải đúng bằng số trong ngoặc vuông của mục. Trả đủ mọi mục.
        2. Dịch câu TIẾNG TRUNG. Bản tiếng Anh chỉ để đối chiếu nghĩa khi câu
           Hán có chỗ mơ hồ — đừng dịch lại tiếng Anh.
        3. Tiếng Việt tự nhiên như người Việt nói, KHÔNG dịch sát từng chữ.
        4. Giữ nguyên sắc thái và thì của câu gốc. Câu hỏi vẫn là câu hỏi.
        5. Một câu tiếng Việt cho một câu tiếng Trung. Không thêm giải thích,
           không thêm ghi chú ngữ pháp, không thêm pinyin.
        6. KHÔNG để lại chữ Hán trong bản dịch.
        7. Giữ dấu câu cuối câu theo đúng kiểu tiếng Việt.
        PROMPT;

        return [
            'prompt' => $prompt,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'i' => ['type' => 'integer'],
                                'vi' => ['type' => 'string'],
                            ],
                            'required' => ['i', 'vi'],
                        ],
                    ],
                ],
                'required' => ['items'],
            ],
        ];
    }

    /**
     * Kiểm một bản dịch trước khi cho nó vào database.
     *
     * `null` = không dùng được, và caller tính đó là một lần hỏng của câu đó.
     *
     * Chốt chặn thật, không phải trang trí: model vẫn để lọt chữ Hán và vẫn kèm
     * giải thích dù prompt đã cấm cả hai.
     */
    public function clean(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $clean = trim((string) preg_replace('/\s+/u', ' ', $raw));

        if ($clean === '' || mb_strlen($clean) > self::MAX_LENGTH) {
            return null;
        }

        // Còn chữ Hán nghĩa là model chép lại một phần câu gốc thay vì dịch.
        if (preg_match('/\p{Han}/u', $clean) === 1) {
            return null;
        }

        return $clean;
    }
}
