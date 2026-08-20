<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dictionary\CedictParser;
use App\Services\Dictionary\DictionaryEnricher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import CC-CEDICT vào `dictionary_words`, rồi gắn HSK / tần suất / tập ưu tiên.
 *
 * Chạy lại được (idempotent): upsert theo khóa tự nhiên
 * (simplified, pinyin_numbered).
 */
final class DictionaryImport extends Command
{
    protected $signature = 'dictionary:import
        {--path= : Đường dẫn cedict_ts.u8 (mặc định storage/app/dictionary)}
        {--hsk= : Đường dẫn JSON danh sách HSK}
        {--frequency= : Đường dẫn JSON SUBTLEX-CH}
        {--skip-enrich : Chỉ import CC-CEDICT, bỏ qua bước gắn nhãn}';

    protected $description = 'Import CC-CEDICT + HSK 2.0 + SUBTLEX-CH vào dictionary_words';

    /**
     * Lô 1000 dòng: đủ lớn để không phải round-trip 125k lần, đủ nhỏ để không
     * dựng một câu SQL khổng lồ vượt giới hạn tham số của driver.
     */
    private const BATCH_SIZE = 1000;

    public function handle(CedictParser $parser, DictionaryEnricher $enricher): int
    {
        $path = $this->resolvePath((string) ($this->option('path') ?? ''), 'cedict_ts.u8');

        if ($path === null) {
            return self::FAILURE;
        }

        $this->info("Đọc {$path}");

        try {
            $imported = $this->importEntries($parser, $path);
        } catch (Throwable $e) {
            $this->error("Import thất bại: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Đã upsert {$imported} mục.");

        if ($this->option('skip-enrich')) {
            return self::SUCCESS;
        }

        return $this->enrich($enricher);
    }

    private function importEntries(CedictParser $parser, string $path): int
    {
        /*
         * CC-CEDICT tách một số từ thành nhiều mục cùng (giản thể, pinyin số) —
         * nghĩa cổ, họ người, biến thể. Postgres từ chối `ON CONFLICT DO UPDATE`
         * chạm cùng một dòng hai lần trong một câu lệnh, nên chúng phải được gộp
         * TRƯỚC khi upsert.
         *
         * Gộp chứ không phải bỏ bớt: mỗi mục mang một nhóm nghĩa khác nhau, giữ
         * lại một mục là im lặng làm mất nghĩa của từ.
         */
        $duplicateKeys = $parser->duplicateKeys($path);
        $this->line('  '.count($duplicateKeys).' khóa trùng trong nguồn sẽ được gộp nghĩa.');

        $batch = [];
        $merged = [];
        $imported = 0;
        $now = now();

        foreach ($parser->parse($path) as $entry) {
            $key = $parser->naturalKey($entry['simplified'], $entry['pinyin_numbered']);

            if (isset($duplicateKeys[$key])) {
                $merged[$key] = $this->mergeEntry($merged[$key] ?? null, $entry);

                continue;
            }

            $batch[] = $this->toRow($entry, $now);

            if (count($batch) >= self::BATCH_SIZE) {
                $imported += $this->flush($batch);
                $batch = [];
                $this->output->write('.');
            }
        }

        if ($batch !== []) {
            $imported += $this->flush($batch);
        }

        foreach (array_chunk(array_values($merged), self::BATCH_SIZE) as $chunk) {
            $imported += $this->flush(array_map(fn (array $e): array => $this->toRow($e, $now), $chunk));
        }

        return $imported;
    }

    /**
     * Gộp hai mục cùng khóa: nối nghĩa, bỏ nghĩa lặp, giữ nguyên thứ tự xuất hiện.
     *
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeEntry(?array $existing, array $incoming): array
    {
        if ($existing === null) {
            return $incoming;
        }

        /** @var list<string> $definitions */
        $definitions = array_values(array_unique([
            ...$existing['definitions_en'],
            ...$incoming['definitions_en'],
        ]));

        return [
            ...$existing,
            'definitions_en' => $definitions,
            'definitions_en_text' => implode('; ', $definitions),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function toRow(array $entry, mixed $now): array
    {
        return [
            ...$entry,
            'definitions_en' => json_encode($entry['definitions_en'], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function flush(array $batch): int
    {
        DB::transaction(function () use ($batch): void {
            /*
             * Cột cập nhật KHÔNG bao gồm `han_viet`, `han_viet_plain`,
             * `han_viet_status`.
             *
             * Đó là dữ liệu của P5, và một phần trong đó là công rà tay
             * (`manual`, xem D10). Import lại phải giữ nguyên chúng — nếu đưa
             * vào danh sách update thì mỗi lần chạy lệnh này là một lần xóa
             * sạch công sửa tay, im lặng.
             */
            DB::table('dictionary_words')->upsert(
                $batch,
                ['simplified', 'pinyin_numbered'],
                [
                    'traditional',
                    'pinyin',
                    'pinyin_plain',
                    'definitions_en',
                    'definitions_en_text',
                    'char_count',
                    'is_single_char',
                    'updated_at',
                ]
            );
        });

        return count($batch);
    }

    private function enrich(DictionaryEnricher $enricher): int
    {
        $frequencyPath = $this->resolvePath((string) ($this->option('frequency') ?? ''), 'subtlex-ch-wf.json');
        $hskPath = $this->resolvePath((string) ($this->option('hsk') ?? ''), 'hsk-complete.json');

        if ($frequencyPath === null || $hskPath === null) {
            return self::FAILURE;
        }

        // Tần suất TRƯỚC: luật gán HSK cho chữ đa âm dựa vào `frequency_rank`
        // để chọn cách đọc phổ biến nhất.
        $this->info('Gắn frequency_rank (SUBTLEX-CH)...');
        $ranked = $enricher->applyFrequencyRanks($frequencyPath);
        $this->line("  {$ranked} dòng có hạng tần suất.");

        $this->info('Gắn hsk_level (HSK 2.0)...');
        $hsk = $enricher->applyHskLevels($hskPath);
        $this->line("  {$hsk['matched']} từ khớp HSK, {$hsk['ambiguous']} từ đa âm không có tần suất — cần rà tay.");

        $this->info('Tính tập ưu tiên...');
        $priority = $enricher->applyPrioritySet();
        $this->line('  '.$priority.' mục là tập ưu tiên (ngưỡng N = '.DictionaryEnricher::FREQUENCY_THRESHOLD.').');

        if ($priority < 8000 || $priority > 10000) {
            // D5 đòi tập này nằm trong 8.000–10.000. Lệch ra ngoài nghĩa là
            // nguồn dữ liệu đã đổi và ngưỡng phải đo lại, không phải đoán tiếp.
            $this->warn("  Tập ưu tiên {$priority} nằm NGOÀI dải 8.000–10.000 mà D5 yêu cầu — đo lại ngưỡng.");
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $option, string $default): ?string
    {
        $path = $option !== '' ? $option : storage_path("app/dictionary/{$default}");

        if (! is_readable($path)) {
            $this->error("Không đọc được: {$path}");

            return null;
        }

        return $path;
    }
}
