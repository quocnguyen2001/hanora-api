<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SearchQueryInterpretation;
use Illuminate\Console\Command;

/**
 * Đo chi phí và hiệu quả cache của lớp diễn giải truy vấn.
 *
 * Lớp làm giàu tốn 123.646 lời gọi rồi dừng. Lớp này thì mỗi truy vấn MỚI là một
 * lời gọi, mãi mãi — nên nó là chỗ duy nhất trong hệ thống có chi phí không trần.
 * Command này là phép đo duy nhất trả lời được "cache đang gánh bao nhiêu phần".
 *
 * KHÔNG dựng bảng log riêng: câu hỏi chỉ có một, và hai cột sẵn có đã trả lời
 * được. Thêm bảng log là thêm chỗ ghi mà không thêm câu trả lời.
 */
final class SearchStats extends Command
{
    protected $signature = 'dictionary:search-stats
        {--price-in=0.25 : USD mỗi 1M token vào}
        {--price-out=1.50 : USD mỗi 1M token ra}
        {--top=10 : Số truy vấn phổ biến nhất cần in}';

    protected $description = 'Thống kê cache diễn giải truy vấn: tỉ lệ trúng và chi phí ước tính';

    /**
     * Token trung bình mỗi lời gọi diễn giải, đo trên 5 lời gọi thật ngày
     * 2026-08-28. Prompt cố định nên con số này ổn định hơn hẳn phía làm giàu.
     */
    private const TOKENS_IN = 450;

    private const TOKENS_OUT = 200;

    public function handle(): int
    {
        $totals = SearchQueryInterpretation::query()
            ->selectRaw('count(*) as rows, coalesce(sum(hit_count), 0) as hits')
            ->selectRaw('count(*) filter (where jsonb_array_length(word_ids) = 0) as empty_rows')
            ->first();

        $calls = (int) ($totals->rows ?? 0);
        $hits = (int) ($totals->hits ?? 0);
        $empty = (int) ($totals->empty_rows ?? 0);
        $served = $calls + $hits;

        if ($served === 0) {
            $this->info('Chưa có truy vấn nào đi qua lớp AI.');

            return self::SUCCESS;
        }

        $costPerCall = (self::TOKENS_IN * (float) $this->option('price-in')
            + self::TOKENS_OUT * (float) $this->option('price-out')) / 1_000_000;

        $this->table(
            ['Lượt phục vụ', 'Lời gọi AI', 'Trúng cache', 'Tỉ lệ trúng', 'Đáp án rỗng', 'Đã tiêu (USD)'],
            [[
                $served,
                $calls,
                $hits,
                number_format($hits / $served * 100, 1).'%',
                $empty,
                number_format($calls * $costPerCall, 4),
            ]]
        );

        /*
         * Không có cache thì mỗi lượt phục vụ là một lời gọi. Hiệu số này là
         * con số biện minh cho toàn bộ bảng cache.
         */
        // Dòng này lặp lại tỉ lệ đã có trong bảng có chủ đích: bảng đẹp để đọc,
        // dòng phẳng để grep trong log vận hành và để test khẳng định.
        $this->line(sprintf(
            'Tỉ lệ trúng cache: %s. Không cache thì cùng lưu lượng này tốn %s USD — cache tiết kiệm %s USD.',
            number_format($hits / $served * 100, 1).'%',
            number_format($served * $costPerCall, 4),
            number_format(($served - $calls) * $costPerCall, 4),
        ));

        $top = SearchQueryInterpretation::query()
            ->orderByDesc('hit_count')
            ->orderBy('id')
            ->limit((int) $this->option('top'))
            ->get(['query_normalized', 'mode', 'hit_count', 'word_ids']);

        if ($top->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['Truy vấn', 'Mode', 'Lượt trúng', 'Số từ'],
                $top->map(fn ($row): array => [
                    $row->query_normalized,
                    $row->mode,
                    $row->hit_count,
                    count($row->word_ids),
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
