<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Enrichment;

use App\Models\DictionaryWord;

/**
 * Quyết định phần nào của một payload AI xứng đáng được cache VĨNH VIỄN.
 *
 * Đây là chốt chặn duy nhất giữa "model nói thế" và "người học đọc thế". Đo trên
 * spike 20 từ HSK ngày 2026-08-28:
 *
 *   từ ghép + thành ngữ đề xuất      72, trong đó  8 không có trong corpus (11%)
 *   câu ví dụ không chứa từ đang tra  4/69
 *   chữ lạ trong phân tích chữ        0/38
 *
 * Nội dung sai ở đây không tự khỏi: nó được ghi một lần rồi phục vụ mãi.
 *
 * **Không bao giờ ném exception.** Đầu vào là văn bản do model sinh, tức dữ liệu
 * KHÔNG tin cậy; một payload lệch hình dạng phải cho ra `null`, không phải 500.
 */
final class EnrichmentValidator
{
    /*
     * Trần số phần tử. Khớp với `EnrichmentPrompt` — đổi một bên thì đổi cả hai.
     *
     * Đây không phải tối ưu dung lượng mà là quyết định giao diện: cùng lý do
     * `DictionaryWordController` giới hạn ví dụ Tatoeba ở 3, một màn chi tiết
     * 40 dòng nghĩa là một bức tường chữ không ai đọc.
     */
    private const MAX_SENSES = 8;

    private const MAX_EXAMPLES = 5;

    private const MAX_RELATED = 8;

    private const MAX_IDIOMS = 4;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null `null` = không đủ dùng, caller ghi `failed`
     */
    public function validate(array $payload, DictionaryWord $word): ?array
    {
        $senses = $this->senses($payload['senses'] ?? null);

        /*
         * Không có nghĩa thì không có gì để hiển thị, và cache một bản ghi rỗng
         * vĩnh viễn là cách hỏng tệ nhất của lớp này: từ đó sẽ KHÔNG BAO GIỜ
         * được sinh lại, vì mọi đường đều thấy "đã có bản ghi rồi".
         */
        if ($senses === []) {
            return null;
        }

        $chars = $this->existingWords(array_merge(
            $this->simplifiedList($payload['related_words'] ?? null),
            $this->simplifiedList($payload['idioms'] ?? null),
        ));

        return [
            'senses' => $senses,
            'examples' => $this->examples($payload['examples'] ?? null, $word),
            'characters' => $this->characters($payload['characters'] ?? null, $word),
            'related_words' => $this->words($payload['related_words'] ?? null, $chars, self::MAX_RELATED, $word),
            'idioms' => $this->words($payload['idioms'] ?? null, $chars, self::MAX_IDIOMS, $word),
            'usage_note' => $this->text($payload['usage_note'] ?? null),
        ];
    }

    /**
     * @return list<array{pos: string, vi: string, note: string|null}>
     */
    private function senses(mixed $raw): array
    {
        $out = [];

        foreach ($this->rows($raw) as $row) {
            $pos = $this->text($row['pos'] ?? null);
            $vi = $this->text($row['vi'] ?? null);

            if ($pos === null || $vi === null) {
                continue;
            }

            $out[] = ['pos' => $pos, 'vi' => $vi, 'note' => $this->text($row['note'] ?? null)];

            if (count($out) >= self::MAX_SENSES) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array{zh: string, pinyin: string, vi: string}>
     */
    private function examples(mixed $raw, DictionaryWord $word): array
    {
        $out = [];

        foreach ($this->rows($raw) as $row) {
            $zh = $this->text($row['zh'] ?? null);
            $pinyin = $this->text($row['pinyin'] ?? null);
            $vi = $this->text($row['vi'] ?? null);

            if ($zh === null || $pinyin === null || $vi === null) {
                continue;
            }

            /*
             * Câu không chứa chính từ đang tra thì không minh hoạ được gì — nó
             * chỉ là một câu tiếng Trung ngẫu nhiên đặt cạnh mục từ. Đo được
             * 4/69 câu rơi vào đây.
             */
            if (! str_contains($zh, $word->simplified)) {
                continue;
            }

            $out[] = ['zh' => $zh, 'pinyin' => $pinyin, 'vi' => $vi];

            if (count($out) >= self::MAX_EXAMPLES) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array{char: string, radical: string, stroke_count: int, meaning_vi: string}>
     */
    private function characters(mixed $raw, DictionaryWord $word): array
    {
        // Ràng buộc, không phải trần: `characters` chỉ được chứa chữ của chính
        // từ đó. Thừa một chữ nghĩa là model đang bịa.
        $allowed = array_flip(mb_str_split($word->simplified));
        $out = [];
        $seen = [];

        foreach ($this->rows($raw) as $row) {
            $char = $this->text($row['char'] ?? null);
            $radical = $this->text($row['radical'] ?? null);
            $meaning = $this->text($row['meaning_vi'] ?? null);
            $strokes = $row['stroke_count'] ?? null;

            if ($char === null || $radical === null || $meaning === null
                || ! isset($allowed[$char]) || isset($seen[$char])
                || ! is_numeric($strokes)) {
                continue;
            }

            $seen[$char] = true;
            $out[] = [
                'char' => $char,
                'radical' => $radical,
                'stroke_count' => (int) $strokes,
                'meaning_vi' => $meaning,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $existing
     * @param  DictionaryWord  $word  Mục từ đang tra — luôn bị loại khỏi kết quả
     * @return list<array{simplified: string, pinyin: string, vi: string}>
     */
    private function words(mixed $raw, array $existing, int $limit, DictionaryWord $word): array
    {
        $out = [];
        // Chính từ đang tra không phải "từ liên quan" của nó. Đo được trên bản
        // sinh thật: 一共 tự liệt kê 一共 ở vị trí đầu, chiếm một suất trong bốn
        // suất hiển thị để nói lại thứ đang ở tiêu đề màn hình.
        $seen = [$word->simplified => true];

        foreach ($this->rows($raw) as $row) {
            $simplified = $this->text($row['simplified'] ?? null);
            $pinyin = $this->text($row['pinyin'] ?? null);
            $vi = $this->text($row['vi'] ?? null);

            if ($simplified === null || $pinyin === null || $vi === null) {
                continue;
            }

            // Không tra được trong corpus thì không hiển thị. Thà thiếu còn hơn
            // đưa một chữ không tồn tại cho người đang học.
            if (! isset($existing[$simplified]) || isset($seen[$simplified])) {
                continue;
            }

            $seen[$simplified] = true;
            $out[] = ['simplified' => $simplified, 'pinyin' => $pinyin, 'vi' => $vi];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * MỘT truy vấn cho cả `related_words` lẫn `idioms`.
     *
     * N+1 ở đây nhân với 123.646 từ, mỗi từ tới 12 đề xuất, là 1,5 triệu truy
     * vấn cho một lần pre-warm. Có test đếm truy vấn khóa lại.
     *
     * @param  list<string>  $words
     * @return array<string, true>
     */
    private function existingWords(array $words): array
    {
        $words = array_values(array_unique(array_filter($words)));

        if ($words === []) {
            return [];
        }

        return array_fill_keys(
            DictionaryWord::query()->whereIn('simplified', $words)->distinct()->pluck('simplified')->all(),
            true,
        );
    }

    /**
     * @return list<string>
     */
    private function simplifiedList(mixed $raw): array
    {
        $out = [];

        foreach ($this->rows($raw) as $row) {
            $simplified = $this->text($row['simplified'] ?? null);

            if ($simplified !== null) {
                $out[] = $simplified;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, is_array(...)));
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
