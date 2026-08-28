<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Search;

/**
 * Prompt hỏi AI xem người dùng đang muốn tìm từ tiếng Trung nào.
 *
 * Đây là toàn bộ phần "hiểu truy vấn" của lớp search. Nó KHÔNG xin nghĩa, không
 * xin pinyin, không xin giải thích — chỉ xin một danh sách chữ giản thể. Mọi
 * thứ khác đã có trong corpus và tra ra chính xác hơn bất kỳ lời sinh nào.
 */
final class InterpretPrompt
{
    /**
     * Tăng khi prompt đổi tới mức cache cũ không còn dùng được.
     *
     * v2: xin thêm `translation`. Bản v1 chỉ trả danh sách từ, nên
     * `bạn có nhớ tôi không?` cho ra 你 / 记得 / 我 / 想念 — các mảnh của câu
     * thay vì câu trả lời. Bản ghi v1 vẫn dùng được cho truy vấn dạng TỪ; chạy
     * lại chúng chỉ để có `translation` là trả tiền cho một trường luôn null.
     */
    public const VERSION = 2;

    /**
     * Xin 10 chứ không phải 8: sau khi tra ngược corpus sẽ rụng bớt (đo trên
     * lớp làm giàu: ~8-11% chữ AI đề xuất không có trong từ điển), và màn tìm
     * kiếm không phân trang nên phần lọt phải đủ dày ngay ở lần đầu.
     */
    private const MAX_WORDS = 10;

    /**
     * @return array{prompt: string, schema: array<string, mixed>}
     */
    public function for(string $query, string $mode): array
    {
        $max = self::MAX_WORDS;

        $intent = $mode === 'cn'
            ? 'Chuỗi này là tiếng Trung hoặc pinyin.'
            : 'Chuỗi này là tiếng Việt: có thể là một từ, một cụm, hoặc cả một câu.';

        $prompt = <<<PROMPT
        Một người Việt đang học tiếng Trung gõ vào ô tìm kiếm của từ điển Trung - Việt:

        "{$query}"

        {$intent}

        Trả về HAI thứ.

        `words`: tối đa {$max} từ tiếng Trung người này nhiều khả năng muốn tra, xếp
        theo độ liên quan giảm dần.

        1. Chỉ trả chữ GIẢN THỂ. Không phồn thể, không pinyin, không giải thích.
        2. Mỗi phần tử là MỘT từ hoặc MỘT thành ngữ tra được trong từ điển.
           Không trả cụm ngữ pháp kiểu "不但……而且……", không trả cả câu.
        3. Nếu truy vấn là một câu, hãy tách ra những từ khoá đáng tra nhất trong câu đó.
        4. Chỉ trả từ CÓ THẬT và phổ thông. Không chắc thì bỏ, đừng đoán.
        5. Nếu truy vấn không có nghĩa gì trong tiếng Việt lẫn tiếng Trung, trả mảng rỗng.

        `translation`: bản dịch tiếng Trung của TOÀN BỘ truy vấn, kèm pinyin.

        6. CHỈ điền khi truy vấn là một CÂU hoặc MỆNH ĐỀ hoàn chỉnh — có chủ ngữ,
           động từ, hoặc là một câu hỏi. Bỏ TRỐNG khi truy vấn chỉ là một từ hay
           một cụm danh từ như "học sinh", "bác sĩ", "xin chào".
        7. Dịch tự nhiên như người Trung Quốc nói, không dịch từng chữ.
        8. `pinyin` có dấu thanh, viết theo từng chữ cách nhau.
        PROMPT;

        return [
            'prompt' => $prompt,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'words' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'translation' => [
                        'type' => 'object',
                        'properties' => [
                            'zh' => ['type' => 'string'],
                            'pinyin' => ['type' => 'string'],
                            'vi' => ['type' => 'string'],
                        ],
                    ],
                ],
                // `translation` KHÔNG required: phần lớn truy vấn là một từ, và
                // ép model điền sẽ khiến nó bịa một "câu" cho `bác sĩ`.
                'required' => ['words'],
            ],
        ];
    }
}
