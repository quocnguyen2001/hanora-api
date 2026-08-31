<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Streak\StreakService;
use Illuminate\Console\Command;

/**
 * Dựng lại ba cột chuỗi từ nguồn.
 *
 * Mạng an toàn cho quyết định materialize chuỗi vào `users`: dữ liệu materialize
 * thì lệch được, và không có lệnh này thì một bug ở đường ghi là mất vĩnh viễn.
 *
 * Phép tính KHÔNG nằm ở đây — nó nằm trong `StreakService::writeFromSource()`,
 * cùng hàm mà đường ghi gọi ở nhánh ghi lùi. Hai cài đặt riêng cho cùng một công
 * thức sẽ trôi khỏi nhau, và lúc đó lệnh "dựng lại" sẽ tự tạo ra chênh lệch thay
 * vì sửa nó.
 */
final class StreakRebuild extends Command
{
    protected $signature = 'hanora:streak-rebuild {--user= : Chỉ dựng lại cho một user}';

    protected $description = 'Dựng lại chuỗi ngày từ review_sessions và user_words';

    public function handle(StreakService $streak): int
    {
        $query = User::query();

        if ($userId = $this->option('user')) {
            if (! ctype_digit((string) $userId)) {
                $this->error('--user phải là id dạng số.');

                return self::INVALID;
            }

            $query->whereKey((int) $userId);
        }

        $touched = 0;

        $query->chunkById(100, function ($users) use ($streak, &$touched): void {
            foreach ($users as $user) {
                $streak->writeFromSource($user);
                $touched++;
            }
        });

        $this->info("Đã dựng lại chuỗi cho {$touched} người dùng.");

        return self::SUCCESS;
    }
}
