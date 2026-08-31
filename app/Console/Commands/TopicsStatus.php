<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Topic;
use App\Services\Topic\TopicCatalog;
use App\Services\Topic\TopicGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cổng deploy cho dữ liệu chủ đề — CHỈ ĐỌC, không ghi gì.
 *
 * Tách khỏi `topics:import` theo đúng khuôn `cvdict:status` và `han-viet:status`,
 * và vì cùng một lý do: thiếu dữ liệu chủ đề hỏng IM LẶNG. Lưới hiện một trang
 * trắng, không lỗi, không log, đúng lúc người dùng bấm "học từ mới".
 *
 * Chạy SAU `topics:import` trong runbook. Frontend chỉ được deploy khi lệnh này
 * PASS.
 */
final class TopicsStatus extends Command
{
    protected $signature = 'topics:status {--min= : Số từ tối thiểu mỗi chủ đề}';

    protected $description = 'Kiểm tra dữ liệu chủ đề (gate deploy)';

    public function handle(): int
    {
        $min = (int) ($this->option('min') ?? TopicGenerator::MIN_WORDS);

        $counts = DB::table('topic_words')
            ->select('topic_id', DB::raw('count(*) as total'))
            ->groupBy('topic_id')
            ->pluck('total', 'topic_id');

        /*
         * CHỈ chủ đề gốc. Chủ đề tự tạo là nội dung cá nhân, không phải điều
         * kiện để deploy — một job hỏng của một người dùng không được chặn cả
         * lần deploy.
         */
        $topics = Topic::query()->whereNull('user_id')->orderBy('sort_order')->get();

        $expected = count(TopicCatalog::slugs());
        $missing = [];
        $thin = [];
        $rows = [];

        foreach (TopicCatalog::all() as $entry) {
            $topic = $topics->firstWhere('slug', $entry['slug']);

            if ($topic === null) {
                $missing[] = $entry['slug'];
                $rows[] = [$entry['slug'], '—', 'THIẾU BẢN GHI'];

                continue;
            }

            $total = (int) ($counts[$topic->id] ?? 0);

            if ($total === 0) {
                $missing[] = $entry['slug'];
            } elseif ($total < $min) {
                $thin[] = "{$entry['slug']}({$total})";
            }

            $rows[] = [
                $entry['slug'],
                (string) $total,
                match (true) {
                    $total === 0 => 'RỖNG',
                    $total < $min => 'mỏng',
                    default => 'ok',
                },
            ];
        }

        $this->table(['Chủ đề', 'Số từ', 'Trạng thái'], $rows);

        if ($topics->count() !== $expected) {
            $this->warn("Bảng `topics` có {$topics->count()} dòng, `TopicCatalog` có {$expected}.");
        }

        if ($missing !== []) {
            $this->error(
                'GATE FAIL: chủ đề rỗng hoặc thiếu — '.implode(', ', $missing).'. '
                .'Lưới chủ đề sẽ hiện thiếu thẻ mà KHÔNG báo lỗi. '
                .'Chạy `php artisan topics:import` (sau `cvdict:status`) trước khi deploy frontend.'
            );

            return self::FAILURE;
        }

        /*
         * Chủ đề mỏng là CẢNH BÁO, không phải fail.
         *
         * Chủ đề hẹp (`thời gian`, `quần áo`) tự nhiên ít từ hơn chủ đề rộng, và
         * ép mọi chủ đề đạt ngưỡng là ép `topics:generate` moi thêm từ đuôi dài
         * — đúng thứ mà cổng chặn của nó tồn tại để tránh.
         */
        if ($thin !== []) {
            $this->warn('Chủ đề ít từ (chấp nhận được, ghi lại để theo dõi): '.implode(', ', $thin));
        }

        $this->info('Dữ liệu chủ đề OK.');

        return self::SUCCESS;
    }
}
