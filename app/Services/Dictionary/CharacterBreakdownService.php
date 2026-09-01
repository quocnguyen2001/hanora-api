<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

use App\Models\DictionaryCharacter;
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
     * @return list<array{
     *     char: string, pinyin: string, han_viet: string|null,
     *     radical: string|null, radical_han_viet: string|null, stroke_count: int|null,
     *     decomposition: string|null, etymology_type: string|null, stroke_names: list<string>|null
     * }>
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
     * @return list<array{
     *     char: string, pinyin: string, han_viet: string|null,
     *     radical: string|null, radical_han_viet: string|null, stroke_count: int|null,
     *     decomposition: string|null, etymology_type: string|null, stroke_names: list<string>|null
     * }>
     */
    private function build(DictionaryWord $word): array
    {
        $characters = mb_str_split($word->simplified);
        $syllables = $this->syllables($word->pinyin_numbered, count($characters));

        /*
         * TỪ MỘT CHỮ CŨNG CÓ phân tích.
         *
         * Trước đây chỗ này thoát sớm với mảng rỗng, lý do là "chữ đơn thì chính
         * nó là phân tích của nó" — đúng khi khối Hán tự chỉ có pinyin và âm
         * Hán-Việt, vì hai thứ đó đã hiện ở hero.
         *
         * Không còn đúng từ khi khối đó mang thêm bộ thủ, số nét, hình thái, lục
         * thư và nét bút: đó là thông tin hero KHÔNG có. Và `剑` — chữ đơn —
         * chính là ca trong ảnh demo của yêu cầu.
         */
        $rows = $this->lookupCharacters($characters);
        $metadata = $this->metadata($characters);
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
                ...$this->attributes($metadata[$character] ?? null),
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
     * Sáu thuộc tính Hán tự, hoặc toàn `null` khi chữ không có trong bảng.
     *
     * Trả ĐỦ khoá kể cả khi không có dữ liệu, thay vì bỏ khoá: hình dạng
     * response phải giống nhau cho mọi chữ, nếu không FE phải kiểm sự tồn tại
     * của từng trường thay vì chỉ kiểm `null`.
     *
     * @return array{
     *     radical: string|null,
     *     radical_han_viet: string|null,
     *     stroke_count: int|null,
     *     decomposition: string|null,
     *     etymology_type: string|null,
     *     stroke_names: list<string>|null
     * }
     */
    private function attributes(?DictionaryCharacter $row): array
    {
        return [
            'radical' => $row?->radical,
            'radical_han_viet' => $row?->radical_han_viet,
            'stroke_count' => $row?->stroke_count,
            'decomposition' => $row?->decomposition,
            'etymology_type' => $row?->etymology_type,
            'stroke_names' => $row?->stroke_names,
        ];
    }

    /**
     * MỘT truy vấn cho cả từ, không phải một truy vấn mỗi chữ.
     *
     * Cùng lý do `EnrichmentValidator::existingWords()` đã ghi: N+1 ở tầng từ
     * điển nhân với số lần người dùng mở một từ là con số không đọc nổi.
     *
     * KHÔNG lấy `strokes`/`medians` — chúng nặng ~4 KB mỗi chữ và đi endpoint
     * riêng. Kéo về đây là bắt mọi người mở màn chi tiết trả phí cho một tính
     * năng thiểu số dùng (xem plan, Validation Q1).
     *
     * @param  list<string>  $characters
     * @return array<string, DictionaryCharacter>
     */
    private function metadata(array $characters): array
    {
        return DictionaryCharacter::query()
            ->whereIn('char', array_values(array_unique($characters)))
            ->get([
                'char', 'radical', 'radical_han_viet',
                'stroke_count', 'decomposition', 'etymology_type', 'stroke_names',
            ])
            ->keyBy('char')
            ->all();
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
