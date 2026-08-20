<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use App\Services\Dictionary\PinyinNormalizer;
use Illuminate\Console\Command;

/**
 * Sửa tay âm Hán-Việt cho một mục từ điển.
 *
 * D10: CHỈ qua artisan trên VPS, không có endpoint và không có UI.
 * `dictionary_words` là nội dung dùng chung cho mọi người dùng; một UI sửa tay
 * cần role, policy và audit trail — cả ba đều ngoài phạm vi MVP.
 *
 * Đặt trạng thái `manual`, và `han-viet:import` không bao giờ ghi đè trạng thái
 * đó.
 */
final class DictionaryOverride extends Command
{
    protected $signature = 'dictionary:override
        {id : ID của mục trong dictionary_words}
        {--han-viet= : Âm Hán-Việt đúng, ví dụ "ngân hàng"}
        {--reset : Trả mục về pending để lần import sau ghép lại}';

    protected $description = 'Sửa tay âm Hán-Việt của một mục từ điển (D10)';

    public function handle(PinyinNormalizer $pinyin): int
    {
        $word = DictionaryWord::find($this->argument('id'));

        if (! $word instanceof DictionaryWord) {
            $this->error("Không tìm thấy mục id={$this->argument('id')}.");

            return self::FAILURE;
        }

        $this->line("  {$word->simplified} ({$word->traditional}) [{$word->pinyin}]");
        $this->line("  hiện tại: {$word->han_viet} [{$word->han_viet_status}]");

        if ($this->option('reset')) {
            $word->update([
                'han_viet' => null,
                'han_viet_plain' => null,
                'han_viet_status' => DictionaryWord::STATUS_PENDING,
            ]);

            $this->info('Đã trả về pending.');

            return self::SUCCESS;
        }

        $hanViet = trim((string) ($this->option('han-viet') ?? ''));

        if ($hanViet === '') {
            $this->error('Cần --han-viet="..." hoặc --reset.');

            return self::FAILURE;
        }

        $word->update([
            'han_viet' => $hanViet,
            'han_viet_plain' => $pinyin->stripDiacritics($hanViet),
            'han_viet_status' => DictionaryWord::STATUS_MANUAL,
        ]);

        $this->info("Đã đặt: {$hanViet} [manual]");

        return self::SUCCESS;
    }
}
