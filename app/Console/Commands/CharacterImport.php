<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use App\Services\Dictionary\MakeMeAHanziParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import Make Me a Hanzi vào `dictionary_characters`.
 *
 * Chạy lại được (idempotent): upsert theo khóa tự nhiên `char`.
 *
 * HAI LƯỢT chứ không ghép hai file trong bộ nhớ. `graphics.txt` nặng 30 MB và
 * giữ 9.574 bản ghi hình học cùng lúc là vài trăm MB trong mảng PHP — thứ mà
 * `CedictParser` đã cố tránh khi chọn generator. Hai lượt cũng khiến import
 * không phụ thuộc vào việc hai file có cùng thứ tự dòng hay không.
 */
final class CharacterImport extends Command
{
    protected $signature = 'characters:import
        {--dictionary= : Đường dẫn dictionary.txt (mặc định storage/app/dictionary)}
        {--graphics= : Đường dẫn graphics.txt}
        {--stroke-names= : JSON do scripts/generate-stroke-names.mjs sinh}';

    protected $description = 'Import Make Me a Hanzi vào dictionary_characters';

    /** Cùng lý do với `DictionaryImport`: đủ lớn để không round-trip 9.574 lần. */
    private const BATCH_SIZE = 500;

    public function handle(MakeMeAHanziParser $parser): int
    {
        $graphics = $this->resolve('graphics', 'graphics.txt');
        $dictionary = $this->resolve('dictionary', 'dictionary.txt');

        if ($graphics === null || $dictionary === null) {
            return self::FAILURE;
        }

        /*
         * Lượt HÌNH HỌC đi trước và là lượt TẠO dòng.
         *
         * Nó là nguồn duy nhất của `stroke_count`, nên chữ nào chỉ có ở
         * `dictionary.txt` mà không có nét thì không đáng tạo dòng: sáu thuộc
         * tính của nó sẽ thiếu mất số nét, và tập viết không chạy được.
         */
        $this->info("Lượt 1 — hình học nét: {$graphics}");
        $strokeNames = $this->strokeNames();
        $created = $this->importGraphics($parser, $graphics, $strokeNames);
        $this->info("Đã upsert {$created} chữ.");

        $this->info("Lượt 2 — bộ thủ, hình thái, lục thư: {$dictionary}");
        $updated = $this->importDictionary($parser, $dictionary);
        $this->info("Đã cập nhật metadata cho {$updated} chữ.");

        $this->info('Lượt 3 — âm Hán-Việt của bộ thủ.');
        $radicals = $this->fillRadicalReadings();
        $this->info("Đã gắn âm Hán-Việt cho {$radicals} bộ thủ.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, list<string>>
     */
    private function strokeNames(): array
    {
        $path = $this->resolve('stroke-names', 'stroke-names.json', required: false);

        if ($path === null) {
            $this->warn('Không có bảng nét bút — cột `stroke_names` sẽ để trống.');

            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->warn("Bảng nét bút hỏng, bỏ qua: {$path}");

            return [];
        }

        $this->info('Bảng nét bút: '.count($decoded).' chữ.');

        return $decoded;
    }

    /**
     * @param  array<string, list<string>>  $strokeNames
     */
    private function importGraphics(MakeMeAHanziParser $parser, string $path, array $strokeNames): int
    {
        $now = now();
        $batch = [];
        $count = 0;

        foreach ($parser->graphics($path) as $entry) {
            $batch[] = [
                'char' => $entry['char'],
                'stroke_count' => $entry['stroke_count'],
                'strokes' => json_encode($entry['strokes'], JSON_UNESCAPED_UNICODE),
                'medians' => json_encode($entry['medians']),
                /*
                 * `null` chứ không mảng rỗng cho chữ cnchar không biết — phần
                 * lớn là phồn thể. Mảng rỗng đọc ra là "chữ này có 0 nét", một
                 * lời khẳng định sai.
                 */
                'stroke_names' => isset($strokeNames[$entry['char']])
                    ? json_encode($strokeNames[$entry['char']], JSON_UNESCAPED_UNICODE)
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $count += $this->flushGraphics($batch);
                $batch = [];
            }
        }

        return $count + ($batch === [] ? 0 : $this->flushGraphics($batch));
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function flushGraphics(array $batch): int
    {
        DB::table('dictionary_characters')->upsert(
            $batch,
            ['char'],
            ['stroke_count', 'strokes', 'medians', 'stroke_names', 'updated_at'],
        );

        return count($batch);
    }

    /**
     * Lượt 2 chỉ CẬP NHẬT, không tạo dòng mới.
     *
     * Chữ có metadata mà không có nét thì lượt 1 đã bỏ qua, và ở đây nó cũng
     * không khớp dòng nào — đúng ý: không dựng một mục Hán tự thiếu số nét.
     */
    private function importDictionary(MakeMeAHanziParser $parser, string $path): int
    {
        $updated = 0;

        DB::transaction(function () use ($parser, $path, &$updated): void {
            foreach ($parser->dictionary($path) as $entry) {
                $updated += DB::table('dictionary_characters')
                    ->where('char', $entry['char'])
                    ->update([
                        'radical' => $entry['radical'],
                        'decomposition' => $entry['decomposition'],
                        'etymology_type' => $entry['etymology_type'],
                        'updated_at' => now(),
                    ]);
            }
        });

        return $updated;
    }

    /**
     * Âm Hán-Việt của bộ thủ, tra từ chính `dictionary_words`.
     *
     * Không dùng `HanVietReadingTable`: nó cần đường dẫn CSV lúc dựng và không
     * resolve được từ container. Quan trọng hơn, `dictionary_words` LÀ nơi âm
     * Hán-Việt đã qua rà tay của P5 nằm — tra chỗ khác là tự tạo nguồn thứ hai.
     *
     * Đo được 244/295 bộ tra ra âm, kể cả biến thể: 刂→đao, 亻→nhân, 氵→thuỷ.
     * Nên KHÔNG cần bảng ánh xạ biến thể về dạng chuẩn, và giữ nguyên biến thể
     * là đúng hình dạng thật xuất hiện trong chữ.
     */
    private function fillRadicalReadings(): int
    {
        $radicals = DB::table('dictionary_characters')
            ->whereNotNull('radical')
            ->distinct()
            ->pluck('radical')
            ->all();

        if ($radicals === []) {
            return 0;
        }

        $readings = DictionaryWord::query()
            ->whereIn('simplified', $radicals)
            ->whereNotNull('han_viet')
            ->where('char_count', 1)
            ->pluck('han_viet', 'simplified');

        $updated = 0;

        DB::transaction(function () use ($readings, &$updated): void {
            foreach ($readings as $radical => $hanViet) {
                $updated += DB::table('dictionary_characters')
                    ->where('radical', $radical)
                    ->update(['radical_han_viet' => $hanViet]);
            }
        });

        return $updated;
    }

    private function resolve(string $option, string $default, bool $required = true): ?string
    {
        $path = (string) ($this->option($option) ?? '');

        if ($path === '') {
            $path = storage_path("app/dictionary/{$default}");
        }

        if (is_readable($path)) {
            return $path;
        }

        if ($required) {
            $this->error("Không đọc được: {$path}");
        }

        return null;
    }
}
