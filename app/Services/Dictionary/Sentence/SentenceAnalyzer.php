<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Sentence;

use App\Models\DictionarySentence;
use App\Models\DictionaryWord;
use App\Services\Gemini\GeminiClient;
use Illuminate\Support\Facades\DB;

/**
 * Phân tích một câu tiếng Trung, cache vĩnh viễn theo chính câu đó.
 *
 * ĐỒNG BỘ, không qua hàng đợi như lớp làm giàu. Đây là nội dung CHÍNH của trang
 * người dùng vừa mở bằng một cú bấm — trả 202 rồi bắt họ đợi poll là biến một
 * trang thành một phòng chờ. Lần thứ hai bất kỳ ai mở câu đó là đọc thẳng cache.
 */
final class SentenceAnalyzer
{
    /** Dài hơn thế không phải câu để tra, mà là một đoạn văn; cũng là trần cột. */
    private const MAX_LENGTH = 200;

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly SentencePrompt $prompts,
    ) {}

    /**
     * @return array<string, mixed>|null `null` = không phân tích được
     */
    public function analyze(string $sentence): ?array
    {
        $normalized = self::normalize($sentence);

        if ($normalized === '') {
            return null;
        }

        $cached = DictionarySentence::query()->where('zh_normalized', $normalized)->first();

        if ($cached?->payload !== null && $cached->prompt_version === SentencePrompt::VERSION) {
            return $cached->payload;
        }

        if ($cached !== null && $cached->attempts >= DictionarySentence::MAX_ATTEMPTS) {
            return null;
        }

        // Lớp AI tắt là trạng thái cấu hình đã biết — đừng đếm nó thành một lần
        // thất bại của câu này, nếu không cắm key vào rồi câu vẫn chết vĩnh viễn.
        $key = config('services.gemini.key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        ['prompt' => $prompt, 'schema' => $schema] = $this->prompts->for($normalized);

        $result = $this->gemini->generate(
            $prompt,
            $schema,
            (int) config('services.gemini.sentence_timeout', 15),
        );

        if (! $result->successful) {
            $this->recordFailure($normalized);

            return null;
        }

        $payload = $this->build($normalized, $result->payload ?? []);

        if ($payload === null) {
            $this->recordFailure($normalized);

            return null;
        }

        DictionarySentence::query()->upsert(
            [[
                'zh_normalized' => $normalized,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'model' => (string) config('services.gemini.model'),
                'prompt_version' => SentencePrompt::VERSION,
                'generated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['zh_normalized'],
            ['payload', 'model', 'prompt_version', 'generated_at', 'updated_at'],
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function build(string $sentence, array $raw): ?array
    {
        $pinyin = self::text($raw['pinyin'] ?? null);
        $vi = self::text($raw['vi'] ?? null);

        // Không có pinyin lẫn bản dịch thì trang chi tiết không có gì để hiện.
        if ($pinyin === null || $vi === null) {
            return null;
        }

        return [
            'zh' => $sentence,
            'pinyin' => $pinyin,
            'vi' => $vi,
            'literal_vi' => self::text($raw['literal_vi'] ?? null),
            'tokens' => $this->tokens($sentence, $raw['tokens'] ?? null),
            'grammar_notes' => $this->notes($raw['grammar_notes'] ?? null),
        ];
    }

    /**
     * Tách từ, kèm `word_id` tra ngược corpus.
     *
     * **Nối mọi `zh` lại phải ra đúng câu gốc — xét trên CHỮ, không xét dấu câu.**
     *
     * Chốt này để bắt model bỏ sót hoặc thêm một chữ, thứ khiến người học đọc
     * một câu khác với câu trên màn hình mà không cách nào thấy bằng mắt.
     *
     * Nhưng so sánh nguyên văn thì quá chặt: đo 5 lần gọi cho `你还记得我吗？`,
     * 1 lần model trả `?` nửa chiều thay cho `？` toàn chiều. Bề rộng dấu câu là
     * TRÌNH BÀY, và vứt cả phần tách từ — thứ giá trị nhất của trang này — vì nó
     * là đánh đổi sai.
     *
     * Nên bỏ hết thứ không phải chữ cái hoặc chữ số ở CẢ HAI vế rồi mới so. Chữ
     * Hán, chữ latin và chữ số đều giữ (câu có thể chứa `A公司`); dấu câu và
     * khoảng trắng bỏ.
     *
     * Không đạt thì trả MẢNG RỖNG chứ không bỏ cả payload: pinyin và bản dịch
     * vẫn dùng được, chỉ mất phần tách từ. FE ẩn hẳn khối đó.
     *
     * @return list<array{zh: string, pinyin: string, vi: string, word_id: int|null}>
     */
    private function tokens(string $sentence, mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $tokens = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $zh = self::text($item['zh'] ?? null);

            if ($zh === null) {
                continue;
            }

            $tokens[] = [
                'zh' => $zh,
                'pinyin' => self::text($item['pinyin'] ?? null) ?? '',
                'vi' => self::text($item['vi'] ?? null) ?? '',
            ];

            if (count($tokens) > SentencePrompt::maxTokens()) {
                return [];
            }
        }

        if ($tokens === []) {
            return [];
        }

        if (self::contentOnly(implode('', array_column($tokens, 'zh'))) !== self::contentOnly($sentence)) {
            return [];
        }

        return $this->attachWordIds($tokens);
    }

    /**
     * Gắn `word_id` để bấm vào một từ trong câu là mở được trang chi tiết từ.
     *
     * MỘT truy vấn cho cả câu. `null` là trạng thái hợp lệ và thường gặp — dấu
     * câu, tên riêng, và những cụm không có trong CC-CEDICT đều rơi vào đó; FE
     * hiện chúng như chữ thường, không bấm được.
     *
     * @param  list<array{zh: string, pinyin: string, vi: string}>  $tokens
     * @return list<array{zh: string, pinyin: string, vi: string, word_id: int|null}>
     */
    private function attachWordIds(array $tokens): array
    {
        $wanted = array_values(array_unique(array_column($tokens, 'zh')));

        $rows = DictionaryWord::query()
            ->whereIn('simplified', $wanted)
            ->orderByRaw('frequency_rank ASC NULLS LAST')
            ->orderBy('id')
            ->get(['id', 'simplified']);

        $byWord = [];

        foreach ($rows as $row) {
            // Một chữ có thể ứng nhiều mục khác âm (行 hành/hàng); lấy mục phổ
            // biến nhất, vì đó là mục người học gặp trong câu này nhiều khả năng nhất.
            $byWord[$row->simplified] ??= (int) $row->id;
        }

        return array_map(
            fn (array $token): array => [...$token, 'word_id' => $byWord[$token['zh']] ?? null],
            $tokens,
        );
    }

    /**
     * @return list<string>
     */
    private function notes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $notes = [];

        foreach ($raw as $note) {
            $text = self::text($note);

            if ($text !== null) {
                $notes[] = $text;
            }

            if (count($notes) >= SentencePrompt::maxNotes()) {
                break;
            }
        }

        return $notes;
    }

    private function recordFailure(string $normalized): void
    {
        DictionarySentence::query()->upsert(
            [[
                'zh_normalized' => $normalized,
                'prompt_version' => SentencePrompt::VERSION,
                // 0 ở đây, UPDATE bên dưới cộng lên 1. Đặt 1 rồi cộng nữa thì
                // lần hỏng ĐẦU TIÊN đã thành 2, và câu cạn lượt sau 2 lần.
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['zh_normalized'],
            ['updated_at'],
        );

        // `attempts` ngoài `$fillable` và tăng bằng UPDATE nguyên tử: nó là bộ
        // đếm nội bộ, và hai request cùng lúc cho một câu hỏng sẽ mất một lượt.
        DictionarySentence::query()
            ->where('zh_normalized', $normalized)
            ->update(['attempts' => DB::raw('attempts + 1')]);
    }

    /** Chỉ giữ chữ cái và chữ số — bỏ dấu câu, khoảng trắng, ký tự trang trí. */
    private static function contentOnly(string $value): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function normalize(string $sentence): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $sentence));

        return mb_substr($normalized, 0, self::MAX_LENGTH);
    }
}
