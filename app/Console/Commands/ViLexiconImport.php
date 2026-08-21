<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dictionary\VnedictParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import VNEDICT vào `vi_en_lexicon`.
 *
 * Chạy lại được: upsert theo khóa tự nhiên `term`.
 */
final class ViLexiconImport extends Command
{
    protected $signature = 'vi-lexicon:import
        {--path= : Đường dẫn vnedict.txt (mặc định database/data)}
        {--skip-checksum : Bỏ qua kiểm SHA-256 — chỉ dùng khi cố ý nâng cấp nguồn}';

    protected $description = 'Import từ điển Việt-Anh (VNEDICT) làm cầu nối tìm kiếm';

    private const BATCH_SIZE = 1000;

    /**
     * SHA-256 của bản VNEDICT 15/02/2019 đang commit trong `database/data/`.
     *
     * Nguồn upstream đi qua HTTP thuần và host không phục vụ được HTTPS, nên
     * không có kênh nào xác thực được file ngoài con số này. Một file bị thay sẽ
     * điều khiển được ánh xạ nghĩa của MỌI truy vấn tiếng Việt trên app — và
     * ngưỡng "đủ số dòng" không phát hiện được điều đó.
     *
     * Đổi nguồn thì đổi hằng số này trong cùng một commit với file mới.
     */
    private const EXPECTED_SHA256 = '01a48269eef3ff7cfb1c9b2e81550b882886c5b8ba09f03aeacaf3b3e019ea24';

    public function handle(VnedictParser $parser): int
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
             * MỘT transaction cho cả lần chạy, khác `DictionaryImport` (mở một
             * transaction mỗi lô).
             *
             * Lý do: `vi-lexicon:status` gác deploy bằng ngưỡng số dòng. Với
             * transaction theo lô, một lần import chết ở dòng 53.000 để lại
             * 53.000 dòng đã commit — vượt ngưỡng 50.000, GATE PASS, và deploy đi
             * tiếp với một lexicon thiếu. Runbook là danh sách lệnh thủ công
             * không có `set -e`, nên exit code khác 0 của import rất dễ bị bỏ qua.
             *
             * Nguyên tử hóa cả lần chạy làm trạng thái dở dang KHÔNG TỒN TẠI, rẻ
             * hơn là dựng thêm cờ hoàn thành để phát hiện nó. 54k dòng đơn giản
             * nằm gọn trong một transaction, và bạn đọc Postgres không bị chặn
             * bởi bên ghi.
             */
            $imported = DB::transaction(fn (): int => $this->importEntries($parser, $path));
        } catch (Throwable $e) {
            $this->error("Import thất bại, KHÔNG dòng nào được ghi: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->newLine();

        $stats = $parser->stats();
        $this->info("Đã upsert {$imported} mục.");
        $this->line("  {$stats['skipped']} mục bỏ (nghĩa chỉ là chú thích trong ngoặc).");
        $this->line("  {$stats['truncated']} mục có gloss bị cắt (nguồn dính hai dòng).");

        /*
         * 0 mục KHÔNG phải thành công.
         *
         * Không có chốt này thì `--skip-checksum` cộng một `--path` sai sẽ in
         * "Đã upsert 0 mục." rồi trả exit 0, và script deploy đi tiếp như thường.
         */
        if ($imported === 0) {
            $this->error('Không mục nào được import — coi là THẤT BẠI, không phải bảng rỗng hợp lệ.');

            return self::FAILURE;
        }

        Cache::forget('vi_lexicon:version');
        $this->line('  Version cache cầu nối đã được làm mới.');

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

    private function importEntries(VnedictParser $parser, string $path): int
    {
        /*
         * VNEDICT có 15 `term` xuất hiện nhiều lần, mỗi lần mang một nhóm nghĩa
         * khác. Postgres từ chối `ON CONFLICT DO UPDATE` chạm cùng một dòng hai
         * lần trong một câu lệnh, nên phải gộp TRƯỚC khi chia lô.
         *
         * Gộp chứ không bỏ bớt: giữ lại một mục là im lặng làm mất nghĩa.
         */
        $duplicateTerms = $parser->duplicateTerms($path);
        $this->line('  '.count($duplicateTerms).' term trùng trong nguồn sẽ được gộp nghĩa.');

        $batch = [];
        $merged = [];
        $imported = 0;
        $now = now();

        foreach ($parser->parse($path) as $entry) {
            if (isset($duplicateTerms[$entry['term']])) {
                $merged[$entry['term']] = $this->mergeEntry($merged[$entry['term']] ?? null, $entry);

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
     * @param  array{term: string, term_plain: string, senses: list<string>}|null  $existing
     * @param  array{term: string, term_plain: string, senses: list<string>}  $incoming
     * @return array{term: string, term_plain: string, senses: list<string>}
     */
    private function mergeEntry(?array $existing, array $incoming): array
    {
        if ($existing === null) {
            return $incoming;
        }

        return [
            ...$existing,
            'senses' => array_values(array_unique([...$existing['senses'], ...$incoming['senses']])),
        ];
    }

    /**
     * @param  array{term: string, term_plain: string, senses: list<string>}  $entry
     * @return array<string, mixed>
     */
    private function toRow(array $entry, mixed $now): array
    {
        return [
            'term' => $entry['term'],
            'term_plain' => $entry['term_plain'],
            'senses' => json_encode($entry['senses'], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function flush(array $batch): int
    {
        // KHÔNG mở transaction ở đây — `handle()` đã bọc cả lần chạy. Xem
        // `importEntries()` để biết vì sao chia lô mà vẫn phải nguyên tử.
        DB::table('vi_en_lexicon')->upsert($batch, ['term'], ['term_plain', 'senses', 'updated_at']);

        return count($batch);
    }

    private function resolvePath(): ?string
    {
        $option = (string) ($this->option('path') ?? '');
        $path = $option !== '' ? $option : database_path('data/vnedict.txt');

        if (! is_readable($path)) {
            $this->error("Không đọc được: {$path}");

            return null;
        }

        return $path;
    }
}
