<?php

declare(strict_types=1);

namespace App\Services\Topic;

use App\Models\DictionaryWord;
use App\Services\Dictionary\VietnameseQueryNormalizer;
use App\Services\Gemini\GeminiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;

/**
 * Sinh bộ từ cho một chủ đề: gọi Gemini theo vòng, đối chiếu corpus, khử nhập
 * nhằng chữ đa âm bằng NGHĨA, rồi xếp hạng theo tần suất.
 *
 * KHÔNG ghi database và KHÔNG ghi file — trả về `TopicGenerationResult` để
 * command quyết định. Tách như vậy vì "sinh xong" và "được phép ghi" là hai câu
 * hỏi khác nhau: chỉ kết cục `Exhausted` mới được ghi ra đĩa.
 */
final class TopicGenerator
{
    /**
     * Một vòng trùng quá tỉ lệ này với các vòng trước → chủ đề đã cạn.
     *
     * Đây là tín hiệu cạn THẬT. Bản kế hoạch đầu dùng "tỉ lệ từ thuộc tập ưu
     * tiên < 40%", nhưng ngưỡng đó không bao giờ bắn: đo trên 4 chủ đề, vòng 1
     * cho 85-97,5% thuộc tập ưu tiên. Model bắt đầu LẶP LẠI CHÍNH NÓ trước khi
     * nó bắt đầu nạo đuôi dài, nên tỉ lệ trùng phát hiện sớm hơn hẳn.
     */
    public const DUPLICATE_CEILING = 0.35;

    /** Trần cứng. Đo được: vòng 3 của "tình yêu" chỉ còn 6/34 từ thuộc tập ưu tiên. */
    public const MAX_ROUNDS = 3;

    /** Dưới mức này thì CẢNH BÁO, không ép — ép là mời model bịa. */
    public const MIN_WORDS = 40;

    /** Số lần thử lại khi gặp 429 trước khi bỏ cuộc cả chủ đề. */
    private const THROTTLE_RETRIES = 3;

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly TopicPrompt $prompt,
        private readonly TopicWordResolver $resolver,
        private readonly VietnameseQueryNormalizer $normalizer,
    ) {}

    public function generate(string $slug, string $promptTerm): TopicGenerationResult
    {
        /** @var array<string, array{zh: string, pinyin: string, vi: string, word: DictionaryWord, rejected: list<array{pinyin: string, vi: string}>}> */
        $accepted = [];
        $rejections = [];
        $roundStats = [];
        $seenZh = [];

        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $items = $this->callRound($promptTerm, $seenZh);

            if ($items instanceof TopicGenerationOutcome) {
                return new TopicGenerationResult(
                    $slug, $items, [], $rejections, $roundStats,
                    $items === TopicGenerationOutcome::Throttled ? 'rate_limited' : 'gemini_failed'
                );
            }

            $duplicates = 0;

            foreach ($items as $item) {
                $zh = trim((string) ($item['zh'] ?? ''));
                $pinyin = trim((string) ($item['pinyin'] ?? ''));
                $vi = trim((string) ($item['vi'] ?? ''));

                if ($zh === '') {
                    continue;
                }

                if (isset($seenZh[$zh])) {
                    $duplicates++;
                    $this->count($rejections, TopicRejection::Duplicate);

                    continue;
                }

                $seenZh[$zh] = true;

                $candidates = $this->resolver->candidatesFor($zh);

                if ($candidates->isEmpty()) {
                    $this->count($rejections, $this->resolver->diagnose($zh));

                    continue;
                }

                [$word, $rejected] = $this->disambiguate($candidates, $pinyin, $vi);

                if (! $word instanceof DictionaryWord) {
                    $this->count($rejections, TopicRejection::Ambiguous);

                    continue;
                }

                $accepted[$zh] = [
                    'zh' => $word->simplified,
                    'pinyin' => $word->pinyin_numbered,
                    'vi' => (string) TopicGloss::teachingFrom($word->definitions_vi),
                    'word' => $word,
                    'rejected' => $rejected,
                    'batch' => $round,
                ];
            }

            $returned = count($items);
            $ratio = $returned === 0 ? 1.0 : $duplicates / $returned;

            $roundStats[] = [
                'round' => $round,
                'returned' => $returned,
                'accepted' => count($accepted),
                'duplicateRatio' => round($ratio, 3),
            ];

            // Cạn vốn từ: dừng SAU khi đã thu hoạch vòng này, không bỏ nó đi.
            if ($ratio > self::DUPLICATE_CEILING) {
                break;
            }
        }

        return new TopicGenerationResult(
            $slug,
            TopicGenerationOutcome::Exhausted,
            $this->rank($accepted),
            $rejections,
            $roundStats,
        );
    }

    /**
     * Một vòng gọi Gemini, tự xử lý 429.
     *
     * `GeminiResult::throttled` KHÔNG tự làm gì cả — nó chỉ có giá trị vì mọi
     * consumer hiện tại là Job và gọi `release()` để trả job về hàng đợi. Một
     * console command chạy đồng bộ không có hàng đợi để release vào, nên nó
     * phải tự chờ. Không có đoạn này thì 429 sẽ giả dạng thành "chủ đề đã cạn".
     *
     * @param  array<string, bool>  $seenZh
     * @return list<array<string, mixed>>|TopicGenerationOutcome
     */
    private function callRound(string $promptTerm, array $seenZh): array|TopicGenerationOutcome
    {
        $built = $this->prompt->for($promptTerm, array_keys($seenZh));

        for ($attempt = 1; $attempt <= self::THROTTLE_RETRIES; $attempt++) {
            $result = $this->gemini->generate($built['prompt'], $built['schema'], 60);

            if ($result->successful) {
                /** @var list<array<string, mixed>> */
                return is_array($result->payload['items'] ?? null) ? $result->payload['items'] : [];
            }

            if (! $result->throttled) {
                return TopicGenerationOutcome::Failed;
            }

            // `max(1, ...)`: một `Retry-After: 0` sẽ biến vòng lặp này thành
            // vòng quay nóng đập vào đúng cái trần vừa chặn nó.
            Sleep::for(max(1, $result->retryAfter))->seconds();
        }

        return TopicGenerationOutcome::Throttled;
    }

    /**
     * Chọn cách đọc đúng cho một chữ Hán (D10).
     *
     * Luật cũ — `is_priority` → `frequency_rank` nhỏ nhất → `id` nhỏ nhất —
     * hỏng vì `frequency_rank` gán theo HÌNH CHỮ: `行/好/会/长` đều hoà tuyệt
     * đối ở hai bậc đầu, nên bậc ba (thứ tự dòng CC-CEDICT) quyết định 100% ca.
     * Kết quả đo được: `东西` → "đông và tây" thay vì "đồ vật".
     *
     * @param  Collection<int, DictionaryWord>  $candidates
     * @return array{0: DictionaryWord|null, 1: list<array{pinyin: string, vi: string}>}
     *                                                                                   Phần tử 0 là `null` khi hoà — để người rà quyết, không đoán bừa.
     */
    private function disambiguate($candidates, string $pinyin, string $modelGloss): array
    {
        if ($candidates->count() === 1) {
            return [$candidates->first(), []];
        }

        /*
         * 1. Pinyin model trả về khớp ĐÚNG MỘT ứng viên.
         *
         * "Đúng một", không phải "cái đầu tiên khớp". `NumberedPinyin` so khớp
         * bỏ qua hoa/thường — cần thiết vì CC-CEDICT viết hoa âm tiết của danh
         * từ riêng — nên `美 Mei3` (Châu Mỹ) và `美 mei3` (đẹp) cùng khớp chuỗi
         * `mei3`. Lấy `first()` ở đó là quay về đúng luật "id nhỏ nhất" mà D10
         * tồn tại để loại bỏ.
         *
         * Đo trên lần sinh thật 16 chủ đề: 5 mục sai kiểu này — `老板` ra
         * "Robam" thay vì "sếp", `波` ra "Ba Lan" thay vì "sóng", `土` ra "dân
         * tộc Thổ" thay vì "đất".
         */
        $byPinyin = $pinyin === ''
            ? $candidates->take(0)
            : $candidates->filter(
                fn (DictionaryWord $w): bool => NumberedPinyin::matches($w->pinyin_numbered, $pinyin)
            );

        if ($byPinyin->count() === 1) {
            $only = $byPinyin->first();

            return [$only, $this->rejectedList($candidates, $only)];
        }

        /*
         * 2. Chấm điểm bằng NGHĨA.
         *
         * Thu hẹp về nhóm khớp pinyin khi có nhiều hơn một: chúng chỉ khác nhau
         * ở hoa/thường, và nghĩa model đưa mới là thứ phân biệt được.
         */
        $scored = ($byPinyin->count() > 1 ? $byPinyin : $candidates)
            ->map(fn (DictionaryWord $w): array => [
                'word' => $w,
                'score' => $this->overlap($modelGloss, (string) TopicGloss::teachingFrom($w->definitions_vi)),
            ])
            ->sortByDesc('score')
            ->values();

        $best = $scored->first();
        $second = $scored->get(1);

        // Hoà, hoặc không ứng viên nào dính dáng tới nghĩa model đưa → để người
        // rà quyết. Đoán bừa ở đây là dạy sai nghĩa, im lặng.
        if ($best['score'] <= 0.0 || ($second !== null && $best['score'] === $second['score'])) {
            return [null, []];
        }

        return [$best['word'], $this->rejectedList($candidates, $best['word'])];
    }

    /**
     * Độ chồng lấn giữa hai nghĩa tiếng Việt, bỏ dấu và bỏ hoa/thường.
     *
     * Dùng `VietnameseQueryNormalizer` đã có để "đồ vật" khớp "do vat" — cùng
     * bộ chuẩn hoá mà nhánh tìm theo nghĩa Việt đang dùng, nên hai chỗ hiểu một
     * chuỗi giống hệt nhau.
     */
    private function overlap(string $a, string $b): float
    {
        $tokens = static fn (string $s): array => array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $s) ?: []
        ));

        $left = $tokens($this->normalizer->normalize($a));
        $right = $tokens($this->normalizer->normalize($b));

        if ($left === [] || $right === []) {
            return 0.0;
        }

        $shared = count(array_intersect($left, $right));

        return $shared / count($left);
    }

    /**
     * @param  Collection<int, DictionaryWord>  $candidates
     * @return list<array{pinyin: string, vi: string}>
     */
    private function rejectedList($candidates, DictionaryWord $chosen): array
    {
        return $candidates
            ->reject(fn (DictionaryWord $w): bool => $w->id === $chosen->id)
            ->map(fn (DictionaryWord $w): array => [
                'pinyin' => $w->pinyin_numbered,
                'vi' => (string) TopicGloss::teachingFrom($w->definitions_vi),
            ])
            ->values()
            ->all();
    }

    /** @param  array<string, int>  $rejections */
    private function count(array &$rejections, TopicRejection $reason): void
    {
        $rejections[$reason->value] = ($rejections[$reason->value] ?? 0) + 1;
    }

    /**
     * Xếp `rank` theo tần suất: thông dụng nhất trước, `NULL` xuống cuối.
     *
     * Màn học bốc ngẫu nhiên trong LÁT CẮT đầu của thứ tự này, nên sai ở đây là
     * dạy `凝聚` trước `爱`.
     *
     * @param  array<string, array<string, mixed>>  $accepted
     * @return list<array{zh: string, pinyin: string, rank: int, batch: int, vi: string, rejected?: list<array{pinyin: string, vi: string}>}>
     */
    private function rank(array $accepted): array
    {
        $rows = array_values($accepted);

        usort($rows, static function (array $a, array $b): int {
            /** @var DictionaryWord $wa */
            $wa = $a['word'];
            /** @var DictionaryWord $wb */
            $wb = $b['word'];

            $ra = $wa->frequency_rank ?? PHP_INT_MAX;
            $rb = $wb->frequency_rank ?? PHP_INT_MAX;

            return $ra <=> $rb ?: ($wa->hsk_level ?? PHP_INT_MAX) <=> ($wb->hsk_level ?? PHP_INT_MAX);
        });

        $out = [];

        foreach ($rows as $index => $row) {
            $entry = [
                'zh' => $row['zh'],
                'pinyin' => $row['pinyin'],
                'rank' => $index + 1,
                'batch' => $row['batch'],
                'vi' => $row['vi'],
            ];

            if ($row['rejected'] !== []) {
                $entry['rejected'] = $row['rejected'];
            }

            $out[] = $entry;
        }

        return $out;
    }
}
