<?php

declare(strict_types=1);

namespace App\Services\Topic;

/**
 * Dựng prompt và JSON Schema cho một vòng sinh từ theo chủ đề.
 *
 * Nguyên tắc giống `EnrichmentPrompt` nhưng ngược chiều: ở đó dữ liệu đã xác
 * minh nạp vào prompt và model chỉ TỔ CHỨC; ở đây model ĐỀ XUẤT và corpus là
 * trọng tài. Đo thật trên 4 chủ đề: 159/160 (99,4%) chữ Hán model trả về có
 * thật trong `dictionary_words` — việc liệt kê từ thông dụng của một chủ đề dễ
 * hơn hẳn việc đoán ý một truy vấn mơ hồ (lớp search đo được 8-11% chữ bịa).
 */
final class TopicPrompt
{
    /**
     * Tăng khi prompt hoặc schema đổi tới mức nội dung cũ không dùng được.
     * Ghi vào JSON để `topics:import` từ chối file sinh bởi prompt đã cũ.
     */
    public const VERSION = 1;

    /** Đo được: xin 40 thì vòng 1 trả về 40, vòng 2 khoảng 37-39. */
    public const WORDS_PER_ROUND = 40;

    /**
     * @param  list<string>  $exclude  Chữ Hán đã có từ các vòng trước
     * @return array{prompt: string, schema: array<string, mixed>}
     */
    public function for(string $promptTerm, array $exclude = []): array
    {
        return [
            'prompt' => $this->prompt($promptTerm, $exclude),
            'schema' => $this->schema(),
        ];
    }

    /**
     * @param  list<string>  $exclude
     */
    private function prompt(string $promptTerm, array $exclude): string
    {
        $count = self::WORDS_PER_ROUND;

        /*
         * Danh sách loại trừ nối bằng `、` (dấu phẩy liệt kê tiếng Trung) chứ
         * không phải dấu phẩy ASCII: nó là dấu ngăn tự nhiên giữa các mục chữ
         * Hán và không lẫn với dấu phẩy bên trong một mục.
         */
        $excludeBlock = $exclude === []
            ? ''
            : "\nKHÔNG được lặp lại các từ đã có: ".implode('、', $exclude);

        /*
         * Xin CẢ `pinyin`, và đây là ràng buộc bắt buộc chứ không phải thông tin
         * thêm cho vui. Khoá tự nhiên của từ điển là (giản thể, pinyin số), và
         * `frequency_rank` được gán theo HÌNH CHỮ chứ không theo âm — nên với
         * chữ đa âm, không có pinyin thì không có cách nào chọn đúng cách đọc
         * ngoài thứ tự dòng trong file nguồn. Đo thật: luật "id nhỏ nhất" cho
         * 东西 = "đông và tây" thay vì "đồ vật".
         */
        return <<<PROMPT
        Liệt kê {$count} từ vựng tiếng Trung giản thể THÔNG DỤNG NHẤT thuộc chủ đề "{$promptTerm}",
        dành cho người Việt học tiếng Trung ở trình độ HSK 1-5.

        QUY TẮC:
        1. Chỉ từ CÓ THẬT và phổ thông. Ưu tiên từ hay dùng nhất trước.
        2. Không tên riêng, không tên người, không tên địa danh.
        3. Không cụm câu, không thành ngữ dài — chỉ từ đơn lẻ.
        4. `pinyin` viết theo dạng CÓ SỐ THANH, cách nhau bằng khoảng trắng: "xi3 huan5", "dong1 xi5".
           Số thanh phải đúng với cách đọc của từ TRONG chủ đề này.
        5. `vi` là nghĩa tiếng Việt ngắn gọn của chính cách đọc đó, không kèm chữ Hán, không kèm pinyin.
        6. Thà ít mà chắc còn hơn nhiều mà đoán.{$excludeBlock}
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'zh' => ['type' => 'string', 'description' => 'Chữ Hán giản thể'],
                            'pinyin' => ['type' => 'string', 'description' => 'Pinyin có số thanh, vd: dong1 xi5'],
                            'vi' => ['type' => 'string', 'description' => 'Nghĩa tiếng Việt ngắn'],
                        ],
                        'required' => ['zh', 'pinyin', 'vi'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }
}
