<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dictionary\CedictParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gắn nghĩa tiếng Việt từ CVDICT vào `dictionary_words`.
 *
 * Chạy lại được: khớp theo khóa tự nhiên (giản thể, pinyin số) — cùng khóa mà
 * `dictionary:import` dùng. Phải chạy SAU `dictionary:import`.
 */
final class CvdictImport extends Command
{
    protected $signature = 'cvdict:import
        {--path= : Đường dẫn cvdict.u8 (mặc định database/data)}
        {--skip-checksum : Bỏ qua kiểm SHA-256 — chỉ dùng khi cố ý nâng cấp nguồn}';

    protected $description = 'Gắn nghĩa tiếng Việt (CVDICT) vào từ điển';

    private const BATCH_SIZE = 1000;

    /**
     * SHA-256 của bản CVDICT 1.0.1 (02/12/2024) đang commit trong `database/data/`.
     *
     * Nội dung này HIỂN THỊ cho người học, không chỉ dùng để khớp truy vấn như
     * VNEDICT trước đây — một file bị thay là nghĩa sai dạy thẳng vào mặt người
     * dùng. Ngưỡng độ phủ của `cvdict:status` không phát hiện được điều đó: một
     * file rác vẫn khớp đủ số dòng nếu giữ nguyên cột khóa.
     *
     * Đổi nguồn thì đổi hằng số này trong cùng một commit với file mới.
     */
    private const EXPECTED_SHA256 = '4dde4b204193efa9c192d7f7daeab1bb579c8ccd7c41ed90d1b6caee22ba0948';

    public function handle(CedictParser $parser): int
    {
        $path = $this->resolvePath();

        if ($path === null) {
            return self::FAILURE;
        }

        if (! $this->verifyChecksum($path)) {
            return self::FAILURE;
        }

        $this->info("Đọc {$path}");

        try {
            /*
             * MỘT transaction cho cả lần chạy, không phải một transaction mỗi lô.
             *
             * `cvdict:status` gác deploy bằng ngưỡng độ phủ, và transaction theo
             * lô làm ngưỡng đó nói dối — một lần chạy chết giữa chừng để lại đủ
             * dòng để gate PASS với một từ điển thiếu một nửa. Runbook là danh
             * sách lệnh thủ công không có `set -e`, nên exit code khác 0 rất dễ
             * bị bỏ qua. Nguyên tử hóa cả lần chạy làm trạng thái dở dang KHÔNG
             * TỒN TẠI, rẻ hơn dựng thêm cờ hoàn thành để phát hiện nó.
             */
            $updated = DB::transaction(fn (): int => $this->importEntries($parser, $path));
        } catch (Throwable $e) {
            $this->error("Import thất bại, KHÔNG dòng nào được ghi: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Đã gắn nghĩa tiếng Việt cho {$updated} dòng.");

        /*
         * 0 dòng KHÔNG phải thành công — nó nghĩa là `dictionary:import` chưa
         * chạy, hoặc `--path` trỏ nhầm file. Cả hai đều phải chặn deploy.
         */
        if ($updated === 0) {
            $this->error(
                'Không dòng nào được cập nhật — coi là THẤT BẠI. '
                .'Chạy `php artisan dictionary:import` trước, và kiểm lại --path.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function verifyChecksum(string $path): bool
    {
        if ($this->option('skip-checksum')) {
            $this->warn('Bỏ qua kiểm SHA-256 theo yêu cầu.');

            return true;
        }

        $actual = hash_file('sha256', $path);

        if ($actual === self::EXPECTED_SHA256) {
            return true;
        }

        $this->error('SHA-256 không khớp — DỪNG, không ghi dòng nào.');
        $this->line('  mong đợi: '.self::EXPECTED_SHA256);
        $this->line('  thực tế:  '.$actual);
        $this->line('  Nếu đây là bản nguồn mới, cập nhật EXPECTED_SHA256 cùng commit với file.');

        return false;
    }

    private function importEntries(CedictParser $parser, string $path): int
    {
        /*
         * CVDICT sinh ra từ CC-CEDICT nên nó thừa hưởng luôn những khóa tự nhiên
         * xuất hiện nhiều lần. `UPDATE ... FROM (VALUES ...)` KHÔNG báo lỗi khi
         * hai dòng VALUES cùng khớp một dòng đích — nó lặng lẽ chọn một, và
         * "chọn một" là không xác định. Gộp trước để lần chạy nào cũng ra cùng
         * kết quả, và để không nghĩa nào bị mất.
         */
        $duplicateKeys = $parser->duplicateKeys($path);
        $this->line('  '.count($duplicateKeys).' khóa trùng trong nguồn sẽ được gộp nghĩa.');

        $batch = [];
        $merged = [];
        $updated = 0;

        foreach ($parser->parseVietnamese($path) as $entry) {
            $key = $parser->naturalKey($entry['simplified'], $entry['pinyin_numbered']);

            if (isset($duplicateKeys[$key])) {
                $merged[$key] = $this->mergeEntry($merged[$key] ?? null, $entry);

                continue;
            }

            $batch[] = $entry;

            if (count($batch) >= self::BATCH_SIZE) {
                $updated += $this->flush($batch);
                $batch = [];
                $this->output->write('.');
            }
        }

        if ($batch !== []) {
            $updated += $this->flush($batch);
        }

        foreach (array_chunk(array_values($merged), self::BATCH_SIZE) as $chunk) {
            $updated += $this->flush($chunk);
        }

        return $updated;
    }

    /**
     * Gộp hai mục cùng khóa: nối nghĩa, bỏ nghĩa lặp, GIỮ NGUYÊN thứ tự xuất hiện.
     *
     * Thứ tự là dữ liệu: xếp hạng tìm kiếm phân biệt "khớp trong nghĩa đầu" với
     * "khớp bất kỳ đâu", nên `array_unique` giữ lần xuất hiện đầu tiên là hành
     * vi đúng, không phải tình cờ.
     *
     * @param  array{simplified: string, pinyin_numbered: string, definitions_vi: list<string>, definitions_vi_text: string}|null  $existing
     * @param  array{simplified: string, pinyin_numbered: string, definitions_vi: list<string>, definitions_vi_text: string}  $incoming
     * @return array{simplified: string, pinyin_numbered: string, definitions_vi: list<string>, definitions_vi_text: string}
     */
    private function mergeEntry(?array $existing, array $incoming): array
    {
        if ($existing === null) {
            return $incoming;
        }

        /** @var list<string> $definitions */
        $definitions = array_values(array_unique([
            ...$existing['definitions_vi'],
            ...$incoming['definitions_vi'],
        ]));

        return [
            ...$existing,
            'definitions_vi' => $definitions,
            'definitions_vi_text' => implode('; ', $definitions),
        ];
    }

    /**
     * `UPDATE ... FROM (VALUES ...)`, KHÔNG phải `upsert`.
     *
     * `upsert` là `INSERT ... ON CONFLICT`, và CVDICT có mục mà `dictionary_words`
     * không có. Một INSERT như vậy hoặc chết vì `definitions_en NOT NULL`, hoặc
     * — tệ hơn — tạo ra dòng từ điển KHÔNG có nghĩa tiếng Anh, không pinyin có
     * dấu, không tần suất. `dictionary:import` sở hữu việc tạo dòng; lệnh này
     * chỉ được gắn thêm hai cột vào dòng đã có.
     *
     * Trả về số dòng THỰC SỰ khớp, không phải kích thước lô — đó là con số mà
     * gate độ phủ cần và là cách duy nhất phát hiện một lô không khớp gì cả.
     *
     * @param  list<array{simplified: string, pinyin_numbered: string, definitions_vi: list<string>, definitions_vi_text: string}>  $batch
     */
    private function flush(array $batch): int
    {
        $now = now();
        $bindings = [$now];
        $rows = [];

        foreach ($batch as $entry) {
            $rows[] = '(?::varchar, ?::varchar, ?::jsonb, ?::text)';
            $bindings[] = $entry['simplified'];
            $bindings[] = $entry['pinyin_numbered'];
            $bindings[] = json_encode($entry['definitions_vi'], JSON_UNESCAPED_UNICODE);
            $bindings[] = $entry['definitions_vi_text'];
        }

        $values = implode(', ', $rows);

        /*
         * CHỈ hai cột nghĩa Việt cộng `updated_at` trong danh sách SET.
         *
         * `definitions_en` tuyệt đối không được có mặt ở đây — cùng loại chốt
         * chặn mà `dictionary:import` dùng để bảo vệ `han_viet` khỏi bị import
         * xóa. Nghĩa tiếng Anh là cơ chế đối chiếu duy nhất người học có khi
         * nghi ngờ một nghĩa dịch máy; ghi đè nó là gỡ mất chốt đó.
         */
        return DB::affectingStatement(<<<SQL
            UPDATE dictionary_words AS d
            SET definitions_vi = v.definitions_vi,
                definitions_vi_text = v.definitions_vi_text,
                updated_at = ?
            FROM (VALUES {$values}) AS v (simplified, pinyin_numbered, definitions_vi, definitions_vi_text)
            WHERE d.simplified = v.simplified
              AND d.pinyin_numbered = v.pinyin_numbered
        SQL, $bindings);
    }

    private function resolvePath(): ?string
    {
        $option = (string) ($this->option('path') ?? '');
        $path = $option !== '' ? $option : database_path('data/cvdict.u8');

        if (! is_readable($path)) {
            $this->error("Không đọc được: {$path}");

            return null;
        }

        return $path;
    }
}
