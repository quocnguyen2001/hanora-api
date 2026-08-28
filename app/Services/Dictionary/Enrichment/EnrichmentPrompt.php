<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Enrichment;

use App\Models\DictionaryWord;

/**
 * Dựng prompt và JSON Schema cho một mục từ.
 *
 * Nguyên tắc: **neo bằng dữ liệu đã xác minh**. Prompt nạp sẵn chữ Hán, pinyin,
 * âm Hán-Việt, nghĩa Anh và nghĩa Việt của chính từ đó, rồi giao cho model đúng
 * việc TỔ CHỨC và MỞ RỘNG. Bảo model "hãy cho tôi biết về 爱" là mời nó bịa;
 * bảo nó "đây là những gì đã biết chắc, hãy sắp xếp theo từ loại và cho ví dụ"
 * thì phần bịa còn lại chỉ nằm ở `related_words`/`idioms` — đúng chỗ mà
 * `EnrichmentValidator` tra ngược được.
 */
final class EnrichmentPrompt
{
    /**
     * Tăng số này khi prompt hoặc schema đổi tới mức nội dung cũ không còn dùng
     * được. Nó được ghi vào `dictionary_word_enrichments.prompt_version` để
     * quét lại hàng loạt bằng `dictionary:enrich --stale`.
     */
    public const VERSION = 1;

    /** Trần số phần tử, khớp với `EnrichmentValidator`. Đổi ở đây thì đổi cả kia. */
    private const MAX_SENSES = 8;

    private const MAX_EXAMPLES = 5;

    private const MAX_RELATED = 8;

    private const MAX_IDIOMS = 4;

    /**
     * @return array{prompt: string, schema: array<string, mixed>}
     */
    public function for(DictionaryWord $word): array
    {
        return [
            'prompt' => $this->prompt($word),
            'schema' => $this->schema(),
        ];
    }

    private function prompt(DictionaryWord $word): string
    {
        $known = [
            'Chữ giản thể' => $word->simplified,
            'Chữ phồn thể' => $word->traditional,
            'Pinyin' => $word->pinyin,
            'Âm Hán-Việt' => $word->han_viet ?? '(chưa có)',
            'Nghĩa tiếng Anh (CC-CEDICT)' => implode(' / ', $word->definitions_en),
            'Nghĩa tiếng Việt (CVDICT)' => $word->definitions_vi === null
                ? '(chưa có)'
                : implode(' / ', $word->definitions_vi),
        ];

        $facts = '';

        foreach ($known as $label => $value) {
            $facts .= "- {$label}: {$value}\n";
        }

        $chars = implode(' ', mb_str_split($word->simplified));

        // Heredoc không nội suy được hằng lớp, nên đưa về biến cục bộ thay vì
        // đẻ ra một loạt getter chỉ để trả lại chính hằng đó.
        $maxSenses = self::MAX_SENSES;
        $maxExamples = self::MAX_EXAMPLES;
        $maxRelated = self::MAX_RELATED;
        $maxIdioms = self::MAX_IDIOMS;

        return <<<PROMPT
        Bạn đang soạn nội dung cho một từ điển Trung - Việt dành cho người Việt học tiếng Trung.

        DỮ LIỆU ĐÃ XÁC MINH của mục từ này:
        {$facts}
        Nhiệm vụ: tổ chức lại và mở rộng dữ liệu trên thành nội dung học tập bằng tiếng Việt.

        RÀNG BUỘC BẮT BUỘC:
        1. KHÔNG được sửa chữ Hán hay pinyin của mục từ. Chúng đã đúng.
        2. Các nghĩa trong `senses` phải bám vào nghĩa đã cho ở trên, chỉ diễn đạt lại
           cho tự nhiên và phân loại theo từ loại. Không thêm nghĩa không có căn cứ.
        3. Mỗi câu trong `examples` BẮT BUỘC chứa nguyên văn chuỗi "{$word->simplified}".
           Câu không chứa nó sẽ bị loại bỏ.
        4. `characters` chỉ được chứa đúng các chữ sau, không thừa không thiếu: {$chars}
        5. `related_words` và `idioms` chỉ được là từ/thành ngữ tiếng Trung CÓ THẬT và
           phổ thông. Nếu không chắc chắn một mục nào có thật, hãy bỏ nó đi thay vì đoán.
           Mục không tra được trong từ điển sẽ bị loại bỏ.
        6. Mọi trường tiếng Việt phải là tiếng Việt tự nhiên, không dịch máy từng chữ,
           không kèm chữ Hán trong ngoặc.

        Giới hạn số lượng: senses tối đa {$maxSenses}, examples tối đa {$maxExamples},
        related_words tối đa {$maxRelated}, idioms tối đa {$maxIdioms}.
        Thà ít mà chắc còn hơn nhiều mà đoán.
        PROMPT;
    }

    /**
     * JSON Schema của payload.
     *
     * `characters` KHÔNG xin `pinyin` của từng chữ, dù màn chi tiết có hiện nó:
     * `CharacterBreakdownService` đã trả pinyin và âm Hán-Việt từ dữ liệu đã xác
     * minh. Xin model sinh lại là tự tạo ra một nguồn thứ hai để hai bên lệch
     * nhau. Ở đây chỉ xin thứ corpus KHÔNG có: bộ thủ, số nét, nghĩa của chữ.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'senses' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'pos' => ['type' => 'string', 'description' => 'Từ loại tiếng Việt: danh từ, động từ, tính từ, trạng từ, ...'],
                            'vi' => ['type' => 'string'],
                            'note' => ['type' => 'string'],
                        ],
                        'required' => ['pos', 'vi'],
                    ],
                ],
                'examples' => [
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
                'characters' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'char' => ['type' => 'string'],
                            'radical' => ['type' => 'string'],
                            'stroke_count' => ['type' => 'integer'],
                            'meaning_vi' => ['type' => 'string'],
                        ],
                        'required' => ['char', 'radical', 'stroke_count', 'meaning_vi'],
                    ],
                ],
                'related_words' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'simplified' => ['type' => 'string'],
                            'pinyin' => ['type' => 'string'],
                            'vi' => ['type' => 'string'],
                        ],
                        'required' => ['simplified', 'pinyin', 'vi'],
                    ],
                ],
                'idioms' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'simplified' => ['type' => 'string'],
                            'pinyin' => ['type' => 'string'],
                            'vi' => ['type' => 'string'],
                        ],
                        'required' => ['simplified', 'pinyin', 'vi'],
                    ],
                ],
                'usage_note' => ['type' => 'string'],
            ],
            'required' => ['senses', 'examples', 'characters'],
        ];
    }
}
