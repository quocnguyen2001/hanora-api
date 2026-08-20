<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use App\Services\Dictionary\HanVietComposer;
use App\Services\Dictionary\HanVietReadingTable;
use App\Services\Dictionary\PinyinNormalizer;
use App\Services\Dictionary\UnihanReadingParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sinh `han_viet`, `han_viet_plain`, `han_viet_status` cho toàn bộ từ điển.
 *
 * Dữ liệu tĩnh, sinh một lần, không phụ thuộc dịch vụ ngoài lúc chạy.
 */
final class HanVietImport extends Command
{
    protected $signature = 'han-viet:import
        {--unihan= : Đường dẫn Unihan_Readings.txt}
        {--supplement= : Đường dẫn bảng Hán-Việt bổ sung (CSV)}
        {--chunk=2000 : Số bản ghi xử lý mỗi lô}';

    protected $description = 'Ghép âm Hán-Việt cho dictionary_words từ Unihan + bảng bổ sung';

    public function handle(UnihanReadingParser $unihanParser, PinyinNormalizer $pinyin): int
    {
        $unihanPath = $this->resolvePath((string) ($this->option('unihan') ?? ''), 'Unihan_Readings.txt');
        $supplementPath = $this->resolvePath((string) ($this->option('supplement') ?? ''), 'hanviet-supplement.csv');

        if ($unihanPath === null || $supplementPath === null) {
            return self::FAILURE;
        }

        $this->info('Nạp bảng tra âm...');
        $table = new HanVietReadingTable($supplementPath, $unihanParser->parse($unihanPath));
        $this->line("  {$table->characterCount()} ký tự có âm Hán-Việt.");

        $composer = new HanVietComposer($table, $pinyin);
        $counts = [
            DictionaryWord::STATUS_OK => 0,
            DictionaryWord::STATUS_AMBIGUOUS => 0,
            DictionaryWord::STATUS_MISSING => 0,
        ];

        $this->info('Ghép âm...');

        /*
         * Bỏ qua bản ghi `manual`: đó là công rà tay của con người (D10), và
         * lệnh này chạy lại nhiều lần trong đời dự án.
         */
        DictionaryWord::query()
            ->where('han_viet_status', '!=', DictionaryWord::STATUS_MANUAL)
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($words) use ($composer, &$counts): void {
                $updates = [];

                foreach ($words as $word) {
                    $composed = $composer->compose($word->traditional, $word->pinyin_numbered);
                    $counts[$composed['han_viet_status']]++;
                    $updates[] = ['id' => $word->id, ...$composed];
                }

                $this->writeBatch($updates);
                $this->output->write('.');
            });

        $this->newLine();
        $this->table(
            ['Trạng thái', 'Số mục'],
            array_map(fn (string $k, int $v): array => [$k, number_format($v)], array_keys($counts), $counts),
        );

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     */
    private function writeBatch(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        /*
         * Một câu UPDATE ... FROM (VALUES ...) cho cả lô.
         *
         * Cách hiển nhiên hơn — một UPDATE cho mỗi dòng — mất ~3,5 phút trên
         * 123k mục, đủ chậm để người ta ngại chạy lại lệnh này. Mà chạy lại
         * được chính là yêu cầu của phase.
         */
        $placeholders = implode(',', array_fill(0, count($updates), '(?::bigint, ?, ?, ?)'));
        $bindings = [];

        foreach ($updates as $update) {
            $bindings[] = $update['id'];
            $bindings[] = $update['han_viet'];
            $bindings[] = $update['han_viet_plain'];
            $bindings[] = $update['han_viet_status'];
        }

        DB::update(
            "UPDATE dictionary_words AS dw
             SET han_viet = v.han_viet,
                 han_viet_plain = v.han_viet_plain,
                 han_viet_status = v.han_viet_status
             FROM (VALUES {$placeholders}) AS v(id, han_viet, han_viet_plain, han_viet_status)
             WHERE dw.id = v.id",
            $bindings
        );
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
