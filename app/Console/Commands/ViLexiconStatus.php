<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ViEnLexiconEntry;
use Illuminate\Console\Command;

/**
 * Cổng sẵn sàng của cầu nối tìm kiếm tiếng Việt.
 *
 * Lý do tồn tại: migration tạo bảng RỖNG, còn nhánh tìm kiếm nghĩa Việt thì im
 * lặng bỏ qua khi cầu nối không trả về từ khóa nào. Deploy thiếu bước import sẽ
 * cho ra một app chạy bình thường, không lỗi, không log — chỉ là gõ `con mèo`
 * không ra gì, trong khi màn Tài khoản đã hứa với người dùng là ra được.
 *
 * Trả exit code khác 0 để script deploy và CI chặn được, thay vì để phát hiện
 * bằng cách người dùng phàn nàn.
 */
final class ViLexiconStatus extends Command
{
    /** Ngưỡng dùng chung với `dictionary:benchmark` — một chỗ khai báo. */
    public const MIN_ENTRIES = 50_000;

    protected $signature = 'vi-lexicon:status {--threshold=50000 : Số mục tối thiểu}';

    protected $description = 'Kiểm tra từ điển cầu nối Việt-Anh đã sẵn sàng chưa (gate deploy)';

    public function handle(): int
    {
        $threshold = (int) $this->option('threshold');
        $total = ViEnLexiconEntry::count();

        $this->info('Từ điển cầu nối Việt-Anh: '.number_format($total).' mục (ngưỡng '.number_format($threshold).')');

        if ($total < $threshold) {
            $this->error(
                'GATE FAIL. Tìm kiếm bằng nghĩa tiếng Việt sẽ im lặng không hoạt động. '
                .'Chạy `php artisan vi-lexicon:import` trước khi cho deploy này ra người dùng.'
            );

            return self::FAILURE;
        }

        $this->info('GATE PASS.');

        return self::SUCCESS;
    }
}
