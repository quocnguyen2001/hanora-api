<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\DictionaryWord;
use App\Models\ReviewSession;
use App\Models\User;
use App\Models\UserWord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dựng payload cho một phiên ôn tập.
 *
 * KHÔNG có state phía server: không `session_id`, không bảng phiên, không Redis.
 * Mỗi lựa chọn trắc nghiệm mang theo `word_id` của từ có âm Hán-Việt đó, nên
 * server chấm được bằng một phép so sánh mà không cần nhớ đã phát ra gì
 * (red team C1).
 */
final class ReviewSessionBuilder
{
    /** Một câu hỏi trắc nghiệm gồm 1 đáp án đúng + 3 distractor. */
    private const DISTRACTOR_COUNT = 3;

    /** Nguồn không có từ nào để ôn. */
    public const EMPTY_NO_WORDS = 'no_words';

    /** Có từ, nhưng không dựng được câu trắc nghiệm nào từ chúng. */
    public const EMPTY_NOT_ENOUGH_OPTIONS = 'not_enough_options';

    /**
     * Trần tỉ lệ từ MỚI trong một phiên `due`.
     *
     * Là TRẦN, không phải hạn mức: kho chưa có từ quá hạn thì phiên vẫn lấp đầy
     * bằng từ mới như trước. Xem `dueWords()` cho lý do đầy đủ.
     */
    private const NEW_WORD_RATIO = 0.30;

    public function __construct(private readonly WeakWordQuery $weakWords) {}

    /**
     * @return array{mode: string, items: list<array<string, mixed>>, empty_reason: string|null}
     */
    public function build(User $user, string $mode, string $source, int $limit): array
    {
        $words = $source === ReviewSession::SOURCE_WEAK
            ? $this->weakWords->forUser($user, $limit)
            : $this->dueWords($user, $limit);

        $items = $mode === AnswerGrader::MODE_MCQ
            ? $this->mcqItems($user, $words)
            : $this->typingItems($words);

        return [
            'mode' => $mode,
            'items' => $items,
            'empty_reason' => $this->emptyReason($words, $items),
        ];
    }

    /**
     * Vì sao phiên rỗng — hai lý do khác nhau cần hai câu trả lời khác nhau.
     *
     * `mcqItems()` loại bỏ mục không đủ 3 distractor khác âm, nên một người có
     * đầy từ hay sai vẫn có thể nhận `items` rỗng ở mode trắc nghiệm. Gộp hai
     * trường hợp làm một sẽ khiến app báo "Chưa có từ nào bạn từng sai" trong
     * khi trang Thống kê đang hiện đúng những từ đó — một câu sai sự thật.
     *
     * @param  Collection<int, UserWord>  $words
     * @param  list<array<string, mixed>>  $items
     */
    private function emptyReason(Collection $words, array $items): ?string
    {
        if ($items !== []) {
            return null;
        }

        return $words->isEmpty() ? self::EMPTY_NO_WORDS : self::EMPTY_NOT_ENOUGH_OPTIONS;
    }

    /**
     * Từ tới hạn: `next_review_at <= now()` hoặc `null` (từ mới), CÓ TRẦN từ mới.
     *
     * Quá hạn lâu nhất lên trước — đó là những từ sắp quên nhất.
     *
     * Trước đây đây là MỘT truy vấn với `ORDER BY next_review_at ASC NULLS
     * FIRST`. Cách đó không chỉ *nhận* từ mới, nó cho chúng quyền ưu tiên TUYỆT
     * ĐỐI, rồi `limit()` cắt sạch từ quá hạn. Khi từ mới còn nhỏ giọt (một từ
     * mỗi lần lưu từ màn tìm kiếm) thì không ai thấy; từ khi màn học chủ đề đổ
     * vào 10 từ mới một lúc, người có 120 từ quá hạn sẽ ôn 8 phiên liên tiếp mà
     * không chạm một từ quá hạn nào — trái hẳn câu ngay trên đây.
     *
     * Trần chỉ đụng truy vấn CHỌN thẻ. Công thức xếp lịch (`SrsScheduler`)
     * không đổi một dòng.
     *
     * @return Collection<int, UserWord>
     */
    private function dueWords(User $user, int $limit): Collection
    {
        $newQuota = max(1, (int) floor($limit * self::NEW_WORD_RATIO));

        $new = $this->eligible($user)
            ->whereNull('next_review_at')
            ->orderBy('id')
            ->limit($newQuota)
            ->get();

        $overdue = $this->eligible($user)
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<=', now())
            ->orderBy('next_review_at')
            ->orderBy('id')
            // `$limit - $new->count()`, KHÔNG `$limit - $newQuota`: kho chỉ có
            // một từ mới thì chín chỗ còn lại phải thuộc về từ quá hạn.
            ->limit($limit - $new->count())
            ->get();

        /*
         * Còn chỗ trống thì lấp thêm bằng từ mới.
         *
         * Đây là thứ giữ cho trần là TRẦN chứ không phải hạn mức: người mới học
         * chưa có từ quá hạn nào vẫn nhận đủ số thẻ như trước.
         */
        $remaining = $limit - $overdue->count() - $new->count();

        if ($remaining > 0) {
            $new = $new->concat(
                $this->eligible($user)
                    ->whereNull('next_review_at')
                    ->whereNotIn('id', $new->modelKeys())
                    ->orderBy('id')
                    ->limit($remaining)
                    ->get()
            );
        }

        // Quá hạn trước, từ mới sau — đúng ý định mà comment gốc đã ghi.
        return $overdue->concat($new)->values();
    }

    /**
     * Nền chung của hai nhánh: từ của user, đã ghép được âm Hán-Việt.
     *
     * CHỈ từ có âm Hán-Việt: cả hai mode đều xoay quanh nó (D13) — trắc nghiệm
     * hỏi nó, mode gõ hiển thị nó làm đề bài. Một từ `missing` cho ra câu hỏi
     * trống: không phải câu hỏi khó, mà là câu hỏi hỏng.
     *
     * @return Builder<UserWord>
     */
    private function eligible(User $user): Builder
    {
        return UserWord::query()
            ->with('word')
            ->where('user_id', $user->id)
            ->whereHas('word', fn ($query) => $query->whereIn('han_viet_status', [
                DictionaryWord::STATUS_OK,
                DictionaryWord::STATUS_MANUAL,
            ]));
    }

    /**
     * @param  Collection<int, UserWord>  $dueWords
     * @return list<array<string, mixed>>
     */
    private function mcqItems(User $user, Collection $dueWords): array
    {
        if ($dueWords->isEmpty()) {
            return [];
        }

        $pool = $this->distractorPool($user, $dueWords);
        $items = [];

        foreach ($dueWords as $userWord) {
            $options = $this->optionsFor($userWord, $pool);

            // Không đủ distractor thì bỏ mục này: một câu trắc nghiệm hai lựa
            // chọn là câu hỏi khác hẳn, và đoán bừa có 50% đúng.
            if (count($options) < self::DISTRACTOR_COUNT + 1) {
                continue;
            }

            $items[] = [
                'user_word_id' => $userWord->id,
                'word' => [
                    'id' => $userWord->word->id,
                    'simplified' => $userWord->word->simplified,
                    'pinyin' => $userWord->word->pinyin,
                ],
                'options' => $options,
            ];
        }

        return $items;
    }

    /**
     * @param  Collection<int, DictionaryWord>  $pool
     * @return list<array{word_id: int, text: string}>
     */
    private function optionsFor(UserWord $userWord, Collection $pool): array
    {
        $answer = $userWord->word;

        $distractors = $pool
            ->where('id', '!=', $answer->id)
            // Khác âm với đáp án: hai lựa chọn cùng chữ là câu hỏi không có đáp
            // án đúng duy nhất.
            ->filter(fn (DictionaryWord $w): bool => $w->han_viet !== $answer->han_viet)
            ->unique('han_viet')
            // Cùng số âm tiết trước: `học tập` lẫn giữa `đông tây`, `tiến bộ`
            // khó hơn hẳn lẫn giữa một từ một tiếng.
            ->sortByDesc(fn (DictionaryWord $w): int => $w->char_count === $answer->char_count ? 1 : 0)
            ->take(self::DISTRACTOR_COUNT);

        $options = $distractors
            ->map(fn (DictionaryWord $w): array => ['word_id' => $w->id, 'text' => (string) $w->han_viet])
            ->values()
            ->all();

        $options[] = ['word_id' => $answer->id, 'text' => (string) $answer->han_viet];

        // XÁO TRỘN. Payload không được đánh dấu lựa chọn nào là đáp án đúng, kể
        // cả bằng vị trí.
        shuffle($options);

        return $options;
    }

    /**
     * Distractor lấy từ CHÍNH kho của user trước — những từ họ đang học lẫn vào
     * nhau mới là bài kiểm tra thật.
     *
     * Kho quá ít từ hợp lệ thì bổ sung từ tập ưu tiên của từ điển, nếu không
     * người mới lưu 3 từ sẽ không ôn được.
     *
     * @param  Collection<int, UserWord>  $dueWords
     * @return Collection<int, DictionaryWord>
     */
    private function distractorPool(User $user, Collection $dueWords): Collection
    {
        $needed = $dueWords->count() * self::DISTRACTOR_COUNT + self::DISTRACTOR_COUNT;

        $fromVocabulary = DictionaryWord::query()
            ->whereIn('id', UserWord::query()
                ->where('user_id', $user->id)
                ->select('word_id'))
            ->whereIn('han_viet_status', [DictionaryWord::STATUS_OK, DictionaryWord::STATUS_MANUAL])
            ->whereNotNull('han_viet')
            ->inRandomOrder()
            ->limit($needed)
            ->get();

        if ($fromVocabulary->count() >= $needed) {
            return $fromVocabulary;
        }

        $fromDictionary = DictionaryWord::query()
            ->where('is_priority', true)
            ->whereIn('han_viet_status', [DictionaryWord::STATUS_OK, DictionaryWord::STATUS_MANUAL])
            ->whereNotNull('han_viet')
            ->whereNotIn('id', $fromVocabulary->pluck('id'))
            ->inRandomOrder()
            ->limit($needed - $fromVocabulary->count())
            ->get();

        return $fromVocabulary->concat($fromDictionary);
    }

    /**
     * @param  Collection<int, UserWord>  $dueWords
     * @return list<array<string, mixed>>
     */
    private function typingItems(Collection $dueWords): array
    {
        return $dueWords->map(fn (UserWord $userWord): array => [
            'user_word_id' => $userWord->id,
            // Hiện âm Hán-Việt, KHÔNG kèm chữ Hán — chữ Hán chính là câu trả lời.
            'prompt_han_viet' => $userWord->word->han_viet,
            'hint' => ['char_count' => $userWord->word->char_count],
        ])->values()->all();
    }
}
