<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use App\Services\Dictionary\Enrichment\EnrichmentPrompt;
use App\Services\Gemini\GeminiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Spike đo chất lượng và chi phí của lớp làm giàu, TRƯỚC khi ghi gì vào database.
 *
 * Tồn tại để trả lời hai câu mà không bảng giá nào trả lời được: nghĩa tiếng
 * Việt sinh ra có tự nhiên không, và một từ thật sự tốn bao nhiêu token. Chốt
 * model bằng bảng giá rồi phát hiện chất lượng kém ở từ thứ 3.000 là cách đắt
 * nhất để học bài học đó.
 *
 * Ghi ra `storage/app/spike/` — thư mục này gitignore, xem `storage/app/.gitignore`.
 */
final class EnrichmentSpike extends Command
{
    protected $signature = 'dictionary:enrich-spike
        {--words=20 : Số từ lấy mẫu}
        {--model= : Ghi đè services.gemini.model cho riêng lần chạy này}
        {--price-in=0.30 : USD mỗi 1M token vào, để quy chi phí ra tiền}
        {--price-out=2.50 : USD mỗi 1M token ra}';

    protected $description = 'Chạy thử lớp làm giàu Gemini trên một nhúm từ HSK và ghi kết quả ra storage/app/spike';

    public function handle(GeminiClient $client, EnrichmentPrompt $prompts): int
    {
        $model = $this->option('model');

        if (is_string($model) && $model !== '') {
            config(['services.gemini.model' => $model]);
        }

        $model = (string) config('services.gemini.model');

        if (! is_string(config('services.gemini.key')) || config('services.gemini.key') === '') {
            $this->error('Thiếu GEMINI_API_KEY trong .env — spike cần gọi API thật.');

            return self::FAILURE;
        }

        /*
         * Lấy mẫu từ tập HSK chứ không phải toàn bộ từ điển: đó đúng là tập sẽ
         * được pre-warm, nên chất lượng đo trên nó mới có ý nghĩa. Lấy ngẫu
         * nhiên để không vô tình chỉ nhìn vào các từ dễ ở đầu bảng.
         */
        $words = DictionaryWord::whereNotNull('hsk_level')
            ->inRandomOrder()
            ->limit((int) $this->option('words'))
            ->get();

        if ($words->isEmpty()) {
            $this->error('Không có từ HSK nào trong database. Chạy dictionary:import trước.');

            return self::FAILURE;
        }

        $directory = storage_path('app/spike');
        File::ensureDirectoryExists($directory);

        $results = [];
        $failures = 0;
        $bar = $this->output->createProgressBar($words->count());
        $bar->start();

        foreach ($words as $word) {
            ['prompt' => $prompt, 'schema' => $schema] = $prompts->for($word);

            $result = $client->generate($prompt, $schema);

            $results[] = [
                'simplified' => $word->simplified,
                'pinyin' => $word->pinyin,
                'han_viet' => $word->han_viet,
                'hsk_level' => $word->hsk_level,
                'definitions_vi' => $word->definitions_vi,
                'ok' => $result->successful,
                'reason' => $result->reason,
                'prompt_chars' => mb_strlen($prompt),
                'tokens_in' => $result->usage['input'],
                'tokens_out' => $result->usage['output'],
                'payload' => $result->payload,
            ];

            if (! $result->successful) {
                $failures++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $file = $directory.'/'.$model.'-'.date('His').'.json';
        File::put($file, json_encode([
            'model' => $model,
            'prompt_version' => EnrichmentPrompt::VERSION,
            'words' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $ok = max(1, $words->count() - $failures);
        $tokensIn = (int) (array_sum(array_column($results, 'tokens_in')) / $ok);
        $tokensOut = (int) (array_sum(array_column($results, 'tokens_out')) / $ok);

        $this->table(
            ['Model', 'Từ', 'OK', 'Hỏng', 'Token vào TB', 'Token ra TB'],
            [[$model, $words->count(), $words->count() - $failures, $failures, $tokensIn, $tokensOut]]
        );

        /*
         * Quy ra tiền ngay tại đây thay vì để người đọc tự nhân.
         *
         * Giá lấy từ bảng giá công bố ngày 2026-08-28 và ĐƯỢC PHÉP lạc hậu — nó
         * chỉ phục vụ một quyết định "có đủ rẻ để phủ toàn bộ từ điển không",
         * chứ không phải hóa đơn. Nhập tay qua tùy chọn khi giá đổi.
         */
        $inPrice = (float) $this->option('price-in');
        $outPrice = (float) $this->option('price-out');
        $perWord = ($tokensIn * $inPrice + $tokensOut * $outPrice) / 1_000_000;

        $this->table(
            ['Phạm vi', 'Số từ', 'Chi phí ước tính (USD)'],
            [
                ['Tập HSK', '4.987', number_format($perWord * 4987, 2)],
                ['Tập ưu tiên', '8.848', number_format($perWord * 8848, 2)],
                ['Toàn bộ từ điển', '123.646', number_format($perWord * 123646, 2)],
            ]
        );

        $this->info("Kết quả: {$file}");

        return $failures === $words->count() ? self::FAILURE : self::SUCCESS;
    }
}
