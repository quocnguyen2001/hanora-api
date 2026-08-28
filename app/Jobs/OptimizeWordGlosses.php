<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DictionaryWord;
use App\Services\Dictionary\Glosses\GlossPrompt;
use App\Services\Gemini\GeminiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Dọn nghĩa tiếng Việt cho một LÔ mục từ và ghi vào các cột `*_vi_ai*`.
 *
 * KHÔNG `ShouldBeUnique`: khác `GenerateWordEnrichment`, job này nhận một lô id
 * khác nhau mỗi lần, nên khóa theo lô không ngăn được trùng lặp thật mà chỉ tạo
 * ra một khóa không bao giờ va nhau.
 */
final class OptimizeWordGlosses implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /**
     * @param  list<int>  $wordIds
     */
    public function __construct(public readonly array $wordIds) {}

    public function handle(GeminiClient $client, GlossPrompt $prompts): void
    {
        $words = DictionaryWord::query()
            ->whereIn('id', $this->wordIds)
            ->orderBy('id')
            ->get();

        if ($words->isEmpty()) {
            return;
        }

        ['prompt' => $prompt, 'schema' => $schema] = $prompts->for($words);

        $result = $this->throttled(fn () => $client->generate($prompt, $schema));

        if ($result === null) {
            $this->release(30);

            return;
        }

        if ($result->throttled) {
            // Tín hiệu nhịp độ, không phải lỗi của lô này.
            $this->release($result->retryAfter);

            return;
        }

        if (! $result->successful) {
            Log::warning('glosses: lô thất bại', [
                'count' => $words->count(),
                'reason' => $result->reason,
            ]);

            return;
        }

        $items = $result->payload['items'] ?? null;

        if (! is_array($items)) {
            return;
        }

        $indexed = $words->values();
        $written = 0;

        foreach ($items as $item) {
            if (! is_array($item) || ! is_numeric($item['i'] ?? null)) {
                continue;
            }

            $word = $indexed->get((int) $item['i']);

            if (! $word instanceof DictionaryWord) {
                // Model trả một chỉ số ngoài lô. Bỏ, đừng đoán nó định nói mục nào.
                continue;
            }

            $glosses = $prompts->clean($item['glosses'] ?? null);

            if ($glosses === []) {
                continue;
            }

            $this->write($word->id, $glosses);
            $written++;
        }

        if ($written < $words->count()) {
            // Model bỏ sót mục là chuyện xảy ra thật ở lô lớn; ghi lại để còn đo
            // được tỉ lệ sót thay vì tưởng đã phủ hết.
            Log::info('glosses: lô thiếu mục', [
                'expected' => $words->count(),
                'written' => $written,
            ]);
        }
    }

    /**
     * @param  list<string>  $glosses
     */
    private function write(int $wordId, array $glosses): void
    {
        $flat = implode('; ', $glosses);
        $first = $glosses[0];

        /*
         * `to_tsvector('simple', …)` và `f_unaccent` — ĐÚNG hai biểu thức mà cột
         * generated của CVDICT dùng. Lệch một chỗ là hai đường cho ra token khác
         * nhau và nhánh AI khớp trượt những truy vấn nhánh cũ khớp trúng.
         *
         * Cột tsvector ở đây là cột THƯỜNG (xem migration), nên phải tự tính tại
         * đây; đó là cái giá đã chọn để tránh khóa bảng 92 MB.
         */
        DB::update(<<<'SQL'
            UPDATE dictionary_words SET
                definitions_vi_ai = ?::jsonb,
                definitions_vi_ai_first = ?,
                definitions_vi_ai_first_plain = f_unaccent(?),
                search_vi_ai_tsv = to_tsvector('simple', ?),
                search_vi_ai_plain_tsv = to_tsvector('simple', f_unaccent(?)),
                vi_ai_model = ?,
                vi_ai_version = ?
            WHERE id = ?
        SQL, [
            json_encode($glosses, JSON_UNESCAPED_UNICODE),
            $first, $first,
            $flat, $flat,
            (string) config('services.gemini.model'),
            GlossPrompt::VERSION,
            $wordId,
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function throttled(callable $callback): mixed
    {
        $rpm = max(1, (int) config('services.gemini.rpm', 10));

        try {
            return Redis::throttle('gemini:glosses')
                ->allow($rpm)
                ->every(60)
                ->block(5)
                ->then($callback, fn () => null);
        } catch (Throwable) {
            // Van giảm áp không được phép là lý do tính năng chết; trần thật vẫn
            // là 429 từ Google.
            return $callback();
        }
    }
}
