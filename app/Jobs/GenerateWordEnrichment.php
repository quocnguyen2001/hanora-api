<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DictionaryWord;
use App\Models\DictionaryWordEnrichment;
use App\Services\Dictionary\Enrichment\EnrichmentPrompt;
use App\Services\Dictionary\Enrichment\EnrichmentValidator;
use App\Services\Gemini\GeminiClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Sinh nội dung làm giàu cho một mục từ.
 *
 * `ShouldBeUnique` là BẮT BUỘC, không phải tối ưu: không có nó, 50 người cùng mở
 * một từ đang hot sẽ đẩy 50 job giống hệt nhau vào hàng đợi và đốt 50 lần quota
 * cho đúng một kết quả.
 */
final class GenerateWordEnrichment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Dài hơn trần 30s của client, chừa chỗ cho throttle và một lượt thử lại.
     * Job phải luôn sống lâu hơn lời gọi mà nó bọc; ngược lại thì job chết giữa
     * chừng và bản ghi kẹt ở `pending` mãi.
     */
    public int $timeout = 90;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /**
     * Khóa unique tự hết hạn sau 10 phút.
     *
     * Mặc định của Laravel là giữ khóa tới khi job CHẠY XONG. Job biến mất giữa
     * chừng — `queue:clear`, worker bị kill, Redis mất dữ liệu — thì khóa nằm
     * lại vĩnh viễn và từ đó KHÔNG BAO GIỜ xếp hàng lại được: mọi lần dispatch
     * sau đó bị bỏ im lặng, không lỗi, không log.
     *
     * Gặp đúng ca này khi chạy thử: xoá hàng đợi rồi xếp lại, 3 job biến mất
     * không dấu vết. 10 phút thoải mái dài hơn một job 90 giây.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $wordId) {}

    public function uniqueId(): string
    {
        return (string) $this->wordId;
    }

    public function handle(
        GeminiClient $client,
        EnrichmentPrompt $prompts,
        EnrichmentValidator $validator,
    ): void {
        $word = DictionaryWord::query()->with('enrichment')->find($this->wordId);

        if ($word === null) {
            return;
        }

        $enrichment = $word->enrichment;

        /*
         * Thoát sớm khi đã có bản ghi dùng được ở đúng phiên bản prompt.
         *
         * Đây là chốt chặn THỨ HAI cho "lần tra thứ hai không gọi API", độc lập
         * với cache HTTP: một job xếp lại do retry, do `dictionary:enrich` chạy
         * lần nữa, hay do người dùng bấm F5 đều dừng ở đây.
         */
        if ($enrichment !== null
            && $enrichment->status === DictionaryWordEnrichment::STATUS_READY
            && $enrichment->prompt_version === EnrichmentPrompt::VERSION) {
            return;
        }

        if ($enrichment !== null && $enrichment->attempts >= DictionaryWordEnrichment::MAX_ATTEMPTS) {
            return;
        }

        ['prompt' => $prompt, 'schema' => $schema] = $prompts->for($word);

        $result = $this->throttled(fn () => $client->generate($prompt, $schema));

        if ($result === null) {
            // Không lấy được lượt trong hàng đợi rate limit — thử lại sau, và
            // KHÔNG tính là một lần thất bại của từ này.
            $this->release(30);

            return;
        }

        if ($result->throttled) {
            // 429 là tín hiệu nhịp độ, không phải lỗi của từ này. `attempts`
            // giữ nguyên, nếu không một đợt pre-warm chạm trần sẽ đánh dấu
            // `failed` hàng loạt từ hoàn toàn bình thường.
            $this->release($result->retryAfter);

            return;
        }

        if (! $result->successful) {
            $this->recordFailure($word, $result->reason ?? 'api_error');

            return;
        }

        $payload = $validator->validate($result->payload ?? [], $word);

        if ($payload === null) {
            $this->recordFailure($word, 'empty_senses');

            return;
        }

        DictionaryWordEnrichment::query()->updateOrCreate(
            ['word_id' => $word->id],
            [
                'status' => DictionaryWordEnrichment::STATUS_READY,
                'payload' => $payload,
                'model' => (string) config('services.gemini.model'),
                'prompt_version' => EnrichmentPrompt::VERSION,
                'failed_reason' => null,
                'generated_at' => now(),
            ],
        );
    }

    /**
     * Giữ nhịp gọi dưới trần RPM cấu hình.
     *
     * `QUEUE_CONNECTION=redis` đã có sẵn nên không thêm hạ tầng. Trả `null` khi
     * không lấy được lượt trong 5 giây — caller `release()` chứ không chờ tiếp,
     * vì chờ trong worker là giữ một slot mà không làm gì.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function throttled(callable $callback): mixed
    {
        $rpm = max(1, (int) config('services.gemini.rpm', 10));

        try {
            return Redis::throttle('gemini:enrich')
                ->allow($rpm)
                ->every(60)
                ->block(5)
                ->then($callback, fn () => null);
        } catch (Throwable) {
            /*
             * Redis không có hoặc `throttle` không dùng được (ví dụ test chạy
             * driver khác). Van giảm áp KHÔNG được phép là lý do tính năng chết
             * — trần thật vẫn là 429 từ Google.
             */
            return $callback();
        }
    }

    /**
     * KHÔNG đặt tên `fail()`: trait `Queueable` đã có `fail()` để đánh dấu job
     * hỏng vĩnh viễn. Trùng tên ở đây sẽ ghi đè nó và biến mọi lần "từ này sinh
     * không được" thành "job này chết", mất luôn cơ chế retry.
     */
    private function recordFailure(DictionaryWord $word, string $reason): void
    {
        $enrichment = DictionaryWordEnrichment::query()->firstOrNew(['word_id' => $word->id]);

        $enrichment->fill([
            'status' => DictionaryWordEnrichment::STATUS_FAILED,
            'failed_reason' => $reason,
        ]);

        // `attempts` ngoài `$fillable` có chủ đích: nó là bộ đếm nội bộ.
        $enrichment->attempts = $enrichment->attempts + 1;
        $enrichment->save();

        Log::warning('enrichment: sinh nội dung thất bại', [
            'word_id' => $word->id,
            'reason' => $reason,
            'attempts' => $enrichment->attempts,
        ]);
    }
}
