<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use Illuminate\Console\Command;

/**
 * Cổng chất lượng của P5.
 *
 * Báo phân bố `han_viet_status` trên tập ưu tiên và trả exit code khác 0 nếu tỉ
 * lệ `ok` dưới ngưỡng — để CI hoặc script deploy chặn được, chứ không chỉ in ra
 * cho người đọc rồi thôi.
 */
final class HanVietStatus extends Command
{
    protected $signature = 'han-viet:status {--threshold=70 : Ngưỡng % `ok` tối thiểu trên tập ưu tiên}';

    protected $description = 'Báo độ phủ âm Hán-Việt trên tập ưu tiên (gate của P5)';

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');
        $total = DictionaryWord::where('is_priority', true)->count();

        if ($total === 0) {
            $this->error('Tập ưu tiên rỗng — chạy dictionary:import trước.');

            return self::FAILURE;
        }

        $rows = [];
        $okCount = 0;

        foreach ([
            DictionaryWord::STATUS_OK,
            DictionaryWord::STATUS_MANUAL,
            DictionaryWord::STATUS_AMBIGUOUS,
            DictionaryWord::STATUS_MISSING,
            DictionaryWord::STATUS_PENDING,
        ] as $status) {
            $count = DictionaryWord::where('is_priority', true)->where('han_viet_status', $status)->count();
            $rows[] = [$status, number_format($count), sprintf('%.1f%%', $count / $total * 100)];

            // `manual` là âm do người rà tay xác nhận — nó hiển thị được, nên
            // tính vào độ phủ giống `ok`.
            if ($status === DictionaryWord::STATUS_OK || $status === DictionaryWord::STATUS_MANUAL) {
                $okCount += $count;
            }
        }

        $this->info("Tập ưu tiên: {$total} mục");
        $this->table(['Trạng thái', 'Số mục', 'Tỉ lệ'], $rows);

        $coverage = $okCount / $total * 100;
        $this->line(sprintf('Hiển thị được: %.1f%% (ngưỡng %.0f%%)', $coverage, $threshold));

        if ($coverage < $threshold) {
            $this->error(
                'GATE FAIL. Dưới ngưỡng này thì dòng Hán-Việt trống quá thường xuyên, '
                .'và cả ôn tập trắc nghiệm (D13) lẫn nhánh tìm kiếm Hán-Việt ở P6 đều mất chỗ dựa. '
                .'Quay lại bàn về nguồn dữ liệu, đừng đi tiếp.'
            );

            return self::FAILURE;
        }

        $this->info('GATE PASS.');

        return self::SUCCESS;
    }
}
