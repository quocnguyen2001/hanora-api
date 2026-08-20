<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use App\Models\DictionaryWord;
use Illuminate\Support\Facades\Cache;

/**
 * Tách một từ thành từng chữ Hán kèm âm đọc ĐÚNG TRONG NGỮ CẢNH của từ đó.
 *
 * Đây là red team H11. Khóa unique của `dictionary_words` cho phép nhiều dòng
 * cho cùng một chữ, mỗi âm một dòng. Tra 行 mà không khớp âm sẽ trả `xíng`
 * ("đi") cho `银行` (yínháng, "ngân hàng") — dạy sai đúng thứ người học đang học.
 *
 * Thuật toán: tách `pinyin_numbered` của từ thành âm tiết, rồi với mỗi ký tự
 * chọn dòng `is_single_char` có `pinyin_numbered` khớp âm tiết TƯƠNG ỨNG. Không
 * khớp thì bỏ hẳn mục đó — thà thiếu một dòng còn hơn hiện một âm sai.
 */
final class CharacterBreakdownService
{
    /** Từ điển là dữ liệu tĩnh, cache được lâu. */
    private const CACHE_TTL = 60 * 60 * 24 * 30;

    public function __construct(private readonly QueryClassifier $classifier) {}

    /**
     * @return list<array{char: string, pinyin: string, han_viet: string|null}>
     */
    public function forWord(DictionaryWord $word): array
    {
        return Cache::remember(
            "dictionary.breakdown.{$word->id}",
            self::CACHE_TTL,
            fn (): array => $this->build($word),
        );
    }

    /**
     * @return list<array{char: string, pinyin: string, han_viet: string|null}>
     */
    private function build(DictionaryWord $word): array
    {
        $characters = mb_str_split($word->simplified);
        $syllables = $this->syllables($word->pinyin_numbered, count($characters));

        // Chữ đơn thì chính nó là phân tích của nó — không cần tra lại.
        if (count($characters) <= 1) {
            return [];
        }

        $rows = $this->lookupCharacters($characters);
        $breakdown = [];

        foreach ($characters as $index => $character) {
            $syllable = $syllables[$index] ?? null;
            $match = $this->matchReading($rows[$character] ?? [], $syllable);

            if ($match === null) {
                continue;
            }

            $breakdown[] = [
                'char' => $character,
                'pinyin' => $match->pinyin,
                'han_viet' => $match->han_viet,
            ];
        }

        return $breakdown;
    }

    /**
     * @param  list<DictionaryWord>  $candidates
     */
    private function matchReading(array $candidates, ?string $syllable): ?DictionaryWord
    {
        if ($candidates === []) {
            return null;
        }

        if ($syllable !== null) {
            foreach ($candidates as $candidate) {
                if ($this->sameSyllable($candidate->pinyin_numbered, $syllable)) {
                    return $candidate;
                }
            }

            // Có âm tiết để đối chiếu mà không dòng nào khớp → bỏ hẳn.
            // Trả về dòng đầu tiên ở đây chính là bug H11.
            return null;
        }

        // Không biết âm tiết (số âm tiết lệch số ký tự): chỉ dám trả lời khi
        // chữ đó chỉ có đúng một cách đọc.
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function sameSyllable(string $left, string $right): bool
    {
        return mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }

    /**
     * @param  list<string>  $characters
     * @return array<string, list<DictionaryWord>>
     */
    private function lookupCharacters(array $characters): array
    {
        $unique = array_values(array_unique(array_filter(
            $characters,
            fn (string $c): bool => $this->classifier->containsHan($c),
        )));

        if ($unique === []) {
            return [];
        }

        return DictionaryWord::query()
            ->where('is_single_char', true)
            ->whereIn('simplified', $unique)
            ->orderByRaw('frequency_rank ASC NULLS LAST')
            ->get()
            ->groupBy('simplified')
            ->map(fn ($group): array => $group->all())
            ->all();
    }

    /**
     * @return list<string|null>
     */
    private function syllables(string $pinyinNumbered, int $characterCount): array
    {
        $parts = preg_split('/\s+/u', trim($pinyinNumbered), -1, PREG_SPLIT_NO_EMPTY);

        // Số âm tiết lệch số ký tự — mục có token lạ hoặc r hóa. Ghép lệch sẽ
        // tra ký tự này bằng âm của ký tự bên cạnh, tức là sai một cách tự tin.
        if ($parts === false || count($parts) !== $characterCount) {
            return array_fill(0, $characterCount, null);
        }

        return $parts;
    }
}
