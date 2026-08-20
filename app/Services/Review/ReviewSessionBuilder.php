<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\DictionaryWord;
use App\Models\User;
use App\Models\UserWord;
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

    /**
     * @return array{mode: string, items: list<array<string, mixed>>}
     */
    public function build(User $user, string $mode, int $limit): array
    {
        $dueWords = $this->dueWords($user, $limit);

        $items = $mode === AnswerGrader::MODE_MCQ
            ? $this->mcqItems($user, $dueWords)
            : $this->typingItems($dueWords);

        return ['mode' => $mode, 'items' => $items];
    }

    /**
     * Từ tới hạn: `next_review_at <= now()` hoặc `null` (từ mới).
     *
     * Quá hạn lâu nhất lên trước — đó là những từ sắp quên nhất.
     *
     * @return Collection<int, UserWord>
     */
    private function dueWords(User $user, int $limit): Collection
    {
        return UserWord::query()
            ->with('word')
            ->where('user_id', $user->id)
            ->where(function ($query): void {
                $query->whereNull('next_review_at')->orWhere('next_review_at', '<=', now());
            })
            /*
             * CHỈ từ đã ghép được âm Hán-Việt.
             *
             * Cả hai mode đều xoay quanh âm Hán-Việt (D13): trắc nghiệm hỏi nó,
             * mode gõ hiển thị nó làm đề bài. Một từ `missing` sẽ cho ra câu hỏi
             * trống — không phải câu hỏi khó, mà là câu hỏi hỏng.
             */
            ->whereHas('word', fn ($query) => $query->whereIn('han_viet_status', [
                DictionaryWord::STATUS_OK,
                DictionaryWord::STATUS_MANUAL,
            ]))
            ->orderByRaw('next_review_at ASC NULLS FIRST')
            ->orderBy('id')
            ->limit($limit)
            ->get();
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
