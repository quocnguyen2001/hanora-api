<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use Illuminate\Console\Command;

/**
 * Cổng độ phủ của nghĩa tiếng Việt.
 *
 * Lý do tồn tại: migration tạo cột RỖNG, và thiếu dữ liệu thì hỏng IM LẶNG —
 * tìm bằng tiếng Việt không ra gì, thẻ từ không hiện nghĩa Việt, không lỗi,
 * không log. Trả exit code khác 0 để script deploy và CI chặn được, thay vì để
 * phát hiện bằng cách người dùng phàn nàn.
 *
 * Gác theo TẬP ƯU TIÊN, không phải toàn corpus. Người học tra từ trong giáo
 * trình chứ không tra từ hiếm, nên độ phủ toàn corpus 93% có thể che một lỗ
 * hổng đúng ở chỗ đau nhất. Toàn corpus vẫn được in ra để đọc.
 */
final class CvdictStatus extends Command
{
    /** Đo được 99,2% trên tập ưu tiên; ngưỡng đặt ở 95% để còn chỗ cho nguồn đổi. */
    public const MIN_PRIORITY_COVERAGE = 95.0;

    protected $signature = 'cvdict:status {--threshold=95 : Ngưỡng % độ phủ tối thiểu trên tập ưu tiên}';

    protected $description = 'Kiểm tra độ phủ nghĩa tiếng Việt (gate deploy)';

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');

        $total = DictionaryWord::count();

        if ($total === 0) {
            $this->error('Từ điển rỗng — chạy `php artisan dictionary:import` trước.');

            return self::FAILURE;
        }

        $withVi = DictionaryWord::whereNotNull('definitions_vi')->count();
        $priority = DictionaryWord::where('is_priority', true)->count();
        $priorityWithVi = DictionaryWord::where('is_priority', true)->whereNotNull('definitions_vi')->count();

        if ($priority === 0) {
            $this->error('Tập ưu tiên rỗng — chạy `php artisan dictionary:import` (kèm bước gắn nhãn) trước.');

            return self::FAILURE;
        }

        $coverage = $priorityWithVi / $priority * 100;

        $this->table(['Phạm vi', 'Có nghĩa Việt', 'Tổng', 'Độ phủ'], [
            ['Toàn corpus', number_format($withVi), number_format($total), sprintf('%.1f%%', $withVi / $total * 100)],
            ['Tập ưu tiên', number_format($priorityWithVi), number_format($priority), sprintf('%.1f%%', $coverage)],
        ]);

        if ($coverage < $threshold) {
            $this->error(sprintf(
                'GATE FAIL: độ phủ tập ưu tiên %.1f%% < %.0f%%. '
                .'Tìm bằng tiếng Việt sẽ im lặng không hoạt động và thẻ từ sẽ thiếu nghĩa Việt. '
                .'Chạy `php artisan cvdict:import` trước khi cho deploy này ra người dùng.',
                $coverage,
                $threshold
            ));

            return self::FAILURE;
        }

        $this->info('GATE PASS.');

        return self::SUCCESS;
    }
}
