<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Glosses;

use App\Models\DictionaryWord;
use Illuminate\Support\Collection;

/**
 * Dọn và sắp lại nghĩa tiếng Việt của một LÔ mục từ.
 *
 * Theo lô chứ không từng từ, và đó là quyết định bắt buộc chứ không phải tối ưu:
 * 115.040 lời gọi riêng lẻ ở 4 giây mỗi lời là hơn 5 ngày chạy liên tục, chưa
 * kể trần RPM. Gộp 20 từ một lời gọi đưa con số đó về ~5.750 lời gọi.
 *
 * Prompt KHÔNG xin dịch lại từ đầu. Nó xin dọn và SẮP LẠI thứ đã có — nghĩa
 * tiếng Anh của CC-CEDICT là mỏ neo, nên model không tự do bịa nghĩa mới.
 */
final class GlossPrompt
{
    /**
     * v1. Tăng khi prompt đổi tới mức nghĩa cũ không dùng được nữa; cột
     * `vi_ai_version` cho phép quét lại đúng phần đã cũ.
     */
    public const VERSION = 1;

    /**
     * 20 từ mỗi lời gọi.
     *
     * Cân giữa hai phía: lô nhỏ thì tốn số lời gọi, lô lớn thì một lỗi JSON làm
     * hỏng cả lô và model bắt đầu lẫn thứ tự các mục. 20 giữ prompt dưới ~4.000
     * token, còn xa trần ngữ cảnh.
     */
    public const BATCH = 20;

    /** Số nghĩa tối đa giữ lại mỗi từ. Nghĩa thứ chín không ai đọc. */
    private const MAX_GLOSSES = 8;

    /**
     * @param  Collection<int, DictionaryWord>  $words
     * @return array{prompt: string, schema: array<string, mixed>}
     */
    public function for(Collection $words): array
    {
        $lines = [];

        foreach ($words->values() as $index => $word) {
            $en = implode(' / ', $word->definitions_en);
            $vi = $word->definitions_vi === null ? '(chưa có)' : implode(' / ', $word->definitions_vi);

            $lines[] = "[{$index}] {$word->simplified} ({$word->pinyin})\n"
                ."    EN: {$en}\n"
                ."    VI hiện tại: {$vi}";
        }

        $items = implode("\n", $lines);
        $max = self::MAX_GLOSSES;

        $prompt = <<<PROMPT
        Bạn đang dọn dữ liệu nghĩa tiếng Việt cho một từ điển Trung - Việt.

        Với mỗi mục dưới đây, trả về danh sách nghĩa tiếng Việt đã DỌN SẠCH và
        SẮP LẠI theo mức phổ biến khi dùng thật, phổ biến nhất đứng đầu.

        {$items}

        RÀNG BUỘC:
        1. `i` phải đúng bằng số trong ngoặc vuông của mục. Trả đủ mọi mục.
        2. Bám vào nghĩa tiếng Anh và nghĩa tiếng Việt đã cho. KHÔNG bịa nghĩa mới.
        3. Sắp theo mức phổ biến THỰC TẾ, không giữ thứ tự cũ. Ví dụ 的 phải bắt
           đầu bằng nghĩa trợ từ sở hữu, không phải "xe taxi".
        4. Bỏ hết chữ Hán, pinyin, mã kiểu [nin2], và tham chiếu chéo tới mục khác.
        5. Bỏ chú thích ngữ pháp trong ngoặc. Giữ lại nhãn phân loại ngắn CHỈ khi
           thiếu nó thì nghĩa sai hẳn — ví dụ "(phương ngữ)".
        6. Mỗi phần tử là một cụm tiếng Việt ngắn, thường 1-4 từ. Không viết cả câu.
        7. Tối đa {$max} nghĩa mỗi mục. Thà ít mà đúng.
        8. Chữ thường hết, trừ tên riêng.
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
                                'glosses' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'required' => ['i', 'glosses'],
                        ],
                    ],
                ],
                'required' => ['items'],
            ],
        ];
    }

    /**
     * Lọc và cắt danh sách nghĩa của MỘT mục.
     *
     * Model vẫn để lọt chữ Hán và mã pinyin dù prompt cấm; đây là chốt chặn thật.
     *
     * @return list<string>
     */
    public function clean(mixed $glosses): array
    {
        if (! is_array($glosses)) {
            return [];
        }

        $out = [];

        foreach ($glosses as $gloss) {
            if (! is_string($gloss)) {
                continue;
            }

            // Bỏ mã pinyin `[nin2]` rồi mới xét chữ Hán, để `您[nin2]` rụng cả cụm.
            $clean = (string) preg_replace('/\[[a-zA-Z]+\d?\]/u', '', $gloss);
            $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

            if ($clean === '' || preg_match('/\p{Han}/u', $clean) === 1) {
                continue;
            }

            $lower = mb_strtolower($clean);

            if (! in_array($lower, $out, true)) {
                $out[] = $lower;
            }

            if (count($out) >= self::MAX_GLOSSES) {
                break;
            }
        }

        return $out;
    }
}
