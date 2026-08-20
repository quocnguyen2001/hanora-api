<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import câu ví dụ Tatoeba cho tập ưu tiên.
 *
 * Chỉ cặp `cmn↔eng` có **link TRỰC TIẾP** (D6). Không bắc cầu qua ngôn ngữ thứ
 * ba: dịch của dịch thì chất lượng không kiểm soát được, và người học sẽ không
 * biết câu tiếng Anh họ đọc đã đi qua mấy lần diễn giải.
 */
final class ExampleImport extends Command
{
    protected $signature = 'examples:import
        {--cmn= : cmn_sentences.tsv}
        {--eng= : eng.tsv}
        {--links= : links.csv}
        {--per-word=3 : Số câu giữ lại cho mỗi từ}';

    protected $description = 'Import câu ví dụ Tatoeba (cmn↔eng link trực tiếp) cho tập ưu tiên';

    /** Câu quá ngắn không dạy được gì; quá dài thì người học bỏ qua. */
    private const IDEAL_MIN = 8;

    private const IDEAL_MAX = 20;

    public function handle(): int
    {
        $cmnPath = $this->resolve((string) ($this->option('cmn') ?? ''), 'cmn_sentences.tsv');
        $engPath = $this->resolve((string) ($this->option('eng') ?? ''), 'eng.tsv');
        $linksPath = $this->resolve((string) ($this->option('links') ?? ''), 'links.csv');

        if ($cmnPath === null || $engPath === null || $linksPath === null) {
            return self::FAILURE;
        }

        $this->info('Đọc câu tiếng Trung...');
        [$chinese, $authors] = $this->readSentences($cmnPath, 'cmn');
        $this->line('  '.count($chinese).' câu.');

        /*
         * Thứ tự ba bước này quan trọng về BỘ NHỚ.
         *
         * Tatoeba có ~2 triệu câu tiếng Anh. Nạp hết vào RAM làm tràn
         * `memory_limit` 128M ngay lập tức (đã gặp). Nhưng chỉ ~73k câu trong số
         * đó thật sự được câu tiếng Trung nào trỏ tới.
         *
         * Nên: đọc link TRƯỚC để biết cần id tiếng Anh nào, rồi mới stream
         * `eng.tsv` và chỉ giữ đúng những id đó.
         */
        $this->info('Ghép link trực tiếp cmn -> eng...');
        $pairs = $this->readDirectLinks($linksPath, $chinese);
        $this->line('  '.count($pairs).' câu tiếng Trung có bản dịch tiếng Anh.');

        $this->info('Đọc câu tiếng Anh được trỏ tới...');
        [$english] = $this->readSentences($engPath, 'eng', array_flip($pairs));
        $this->line('  '.count($english).' câu.');

        $this->info('Khớp câu với tập ưu tiên...');

        return $this->attachToWords($chinese, $english, $authors, $pairs);
    }

    /**
     * @param  array<string, mixed>|null  $onlyIds  chỉ giữ id có trong tập này
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function readSentences(string $path, string $language, ?array $onlyIds = null): array
    {
        $sentences = [];
        $authors = [];
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [[], []];
        }

        try {
            while (($row = fgetcsv($handle, 0, "\t", '"', '')) !== false) {
                if (! isset($row[1], $row[2]) || $row[1] !== $language) {
                    continue;
                }

                if ($onlyIds !== null && ! isset($onlyIds[(string) $row[0]])) {
                    continue;
                }

                $sentences[(string) $row[0]] = (string) $row[2];

                if (isset($row[3]) && $row[3] !== '\\N') {
                    $authors[(string) $row[0]] = (string) $row[3];
                }
            }
        } finally {
            fclose($handle);
        }

        return [$sentences, $authors];
    }

    /**
     * @param  array<string, string>  $chinese
     * @return array<string, string> id câu Trung => id câu Anh đầu tiên
     */
    private function readDirectLinks(string $path, array $chinese): array
    {
        $pairs = [];
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            while (($row = fgetcsv($handle, 0, "\t", '"', '')) !== false) {
                if (! isset($row[0], $row[1])) {
                    continue;
                }

                $from = (string) $row[0];
                $to = (string) $row[1];

                /*
                 * Chỉ giữ chiều cmn -> X, và chỉ link TRỰC TIẾP.
                 *
                 * Chưa lọc được "X có phải tiếng Anh không" ở đây vì `eng.tsv`
                 * chưa đọc — nhưng id không phải tiếng Anh sẽ tự rụng ở bước
                 * sau, khi tra `$english[$englishId]` không thấy.
                 */
                if (isset($chinese[$from]) && ! isset($pairs[$from])) {
                    $pairs[$from] = $to;
                }
            }
        } finally {
            fclose($handle);
        }

        return $pairs;
    }

    /**
     * @param  array<string, string>  $chinese
     * @param  array<string, string>  $english
     * @param  array<string, string>  $authors
     * @param  array<string, string>  $pairs
     */
    private function attachToWords(array $chinese, array $english, array $authors, array $pairs): int
    {
        $perWord = max(1, (int) $this->option('per-word'));

        /*
         * Chỉ tập ưu tiên.
         *
         * Quét 120k từ × 62k câu là 7,4 tỉ phép so khớp chuỗi. Tập ưu tiên là
         * đúng những từ người học gặp, và giới hạn ở đó biến bài toán thành
         * ~500 triệu phép — chạy được trong vài phút.
         */
        $words = DictionaryWord::query()
            ->where('is_priority', true)
            ->orderBy('char_count', 'desc')
            ->get(['id', 'simplified', 'char_count'])
            ->groupBy('simplified')
            ->map(fn ($group) => $group->first());

        /*
         * Giữ TỐI ĐA `perWord` câu tốt nhất cho mỗi từ NGAY TRONG LÚC quét.
         *
         * Cách hiển nhiên hơn — gom hết ứng viên rồi mới sắp xếp và cắt — làm
         * tràn bộ nhớ (đã gặp): một từ rất thường dùng như 我 khớp hàng chục
         * nghìn câu, và mỗi ứng viên là một dòng đầy đủ. Chặn ở đây giữ bộ nhớ
         * tỉ lệ với SỐ TỪ chứ không phải số cặp (từ × câu).
         */
        $best = [];
        $now = now();

        foreach ($pairs as $chineseId => $englishId) {
            // Link trỏ sang ngôn ngữ khác tiếng Anh: bỏ qua.
            if (! isset($english[$englishId])) {
                continue;
            }

            $sentence = $chinese[$chineseId] ?? '';
            $length = mb_strlen($sentence);
            $score = $this->score($length);

            foreach ($words as $simplified => $word) {
                if (! str_contains($sentence, (string) $simplified)) {
                    continue;
                }

                $kept = $best[$word->id] ?? [];

                // Đã đủ chỗ và câu này không hơn câu tệ nhất đang giữ → bỏ.
                if (count($kept) >= $perWord && $score <= (int) end($kept)['quality_score']) {
                    continue;
                }

                $kept[] = [
                    'word_id' => $word->id,
                    'sentence_zh' => $sentence,
                    'translation_en' => $english[$englishId],
                    'contributor' => $authors[$chineseId] ?? null,
                    'license' => 'CC BY 2.0 FR',
                    'char_length' => min($length, 32767),
                    'quality_score' => $score,
                    'source' => 'tatoeba',
                    'source_id' => $chineseId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                usort($kept, fn (array $a, array $b): int => $b['quality_score'] <=> $a['quality_score']);

                $best[$word->id] = array_slice($kept, 0, $perWord);
            }
        }

        $rows = [];

        foreach ($best as $wordExamples) {
            array_push($rows, ...$wordExamples);
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('dictionary_examples')->upsert(
                $chunk,
                ['word_id', 'source', 'source_id'],
                ['sentence_zh', 'translation_en', 'contributor', 'license', 'quality_score', 'updated_at'],
            );
            $this->output->write('.');
        }

        $this->newLine();
        $this->info(count($rows).' câu ví dụ cho '.count($best).' từ.');

        return self::SUCCESS;
    }

    /**
     * Ưu tiên câu dài vừa phải. Câu 3 chữ không dạy được ngữ cảnh; câu 60 chữ
     * thì người học bỏ qua.
     */
    private function score(int $length): int
    {
        if ($length >= self::IDEAL_MIN && $length <= self::IDEAL_MAX) {
            return 100;
        }

        $distance = $length < self::IDEAL_MIN
            ? self::IDEAL_MIN - $length
            : $length - self::IDEAL_MAX;

        return max(0, 100 - $distance * 4);
    }

    private function resolve(string $option, string $default): ?string
    {
        $path = $option !== '' ? $option : storage_path("app/dictionary/{$default}");

        if (! is_readable($path)) {
            $this->error("Không đọc được: {$path}");

            return null;
        }

        return $path;
    }
}
