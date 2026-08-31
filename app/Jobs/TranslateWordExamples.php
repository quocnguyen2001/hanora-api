<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DictionaryExample;
use App\Models\DictionaryWord;
use App\Services\Dictionary\Examples\ExampleTranslationPrompt;
use App\Services\Gemini\GeminiClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Dịch sang tiếng Việt các câu ví dụ của một từ, trong MỘT lời gọi Gemini.
 *
 * `ShouldBeUnique` là BẮT BUỘC, không phải tối ưu: không có nó, 50 người cùng mở
 * một từ đang hot sẽ đẩy 50 job giống hệt nhau vào hàng đợi và tiêu 50 lời gọi
 * Gemini cho đúng một kết quả. Cùng lý do `ResolveWordIllustration` đã ghi.
 */
final class TranslateWordExamples implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Dài hơn hẳn trần 30 giây của client, chừa chỗ cho throttle và một lượt
     * thử lại. Job phải luôn sống lâu hơn lời gọi mà nó bọc; ngược lại job chết
     * giữa chừng và câu kẹt ở trạng thái chưa dịch mà không ai đếm là hỏng.
     */
    public int $timeout = 120;

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
     * Đã gặp đúng ca này ở lớp làm giàu bằng Gemini. Đừng bỏ dòng này.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $wordId) {}

    public function uniqueId(): string
    {
        return (string) $this->wordId;
    }

    public function handle(GeminiClient $client, ExampleTranslationPrompt $prompts): void
    {
        $word = DictionaryWord::query()->find($this->wordId);

        if ($word === null) {
            return;
        }

        $pending = self::pending($word);

        /*
         * Thoát sớm khi không còn gì để dịch.
         *
         * Chốt chặn THỨ HAI cho "lần mở thứ hai không gọi Gemini", độc lập với
         * cache HTTP: job xếp lại do retry, do người dùng bấm F5, hay do người
         * khác mở cùng một từ đều dừng ở đây.
         */
        if ($pending->isEmpty()) {
            return;
        }

        /*
         * Lớp AI tắt là trạng thái cấu hình đã biết, không phải sự cố đang diễn
         * ra — đừng đếm nó thành một lần thất bại, nếu không cắm key vào rồi câu
         * vẫn chết vĩnh viễn. Cùng cách `SentenceAnalyzer` xử lý.
         */
        $key = config('services.gemini.key');

        if (! is_string($key) || $key === '') {
            return;
        }

        ['prompt' => $prompt, 'schema' => $schema] = $prompts->for($pending);

        $result = $this->throttled(fn () => $client->generate($prompt, $schema));

        if ($result === null) {
            // Không lấy được lượt trong hàng đợi rate limit — thử lại sau, và
            // KHÔNG tính là một lần thất bại của từ này.
            $this->release(30);

            return;
        }

        if ($result->throttled) {
            // 429 là tín hiệu nhịp độ, không phải lỗi của từ này. `vi_attempts`
            // giữ nguyên, nếu không một đợt chạm trần sẽ đánh dấu hỏng hàng
            // loạt câu hoàn toàn bình thường.
            $this->release($result->retryAfter);

            return;
        }

        if (! $result->successful) {
            Log::warning('example-translation: lô thất bại', [
                'word_id' => $word->id,
                'reason' => $result->reason,
            ]);

            $this->recordFailure($pending);

            return;
        }

        $items = $result->payload['items'] ?? null;

        if (! is_array($items)) {
            $this->recordFailure($pending);

            return;
        }

        $written = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! is_numeric($item['i'] ?? null)) {
                continue;
            }

            $example = $pending->get((int) $item['i']);

            if (! $example instanceof DictionaryExample) {
                // Model trả một chỉ số ngoài lô. Bỏ, đừng đoán nó định nói câu nào.
                continue;
            }

            $translation = $prompts->clean($item['vi'] ?? null);

            if ($translation === null) {
                continue;
            }

            $example->forceFill([
                'translation_vi' => $translation,
                'vi_version' => ExampleTranslationPrompt::VERSION,
            ])->save();

            $written[] = $example->id;
        }

        /*
         * Câu bị model bỏ sót hoặc trả về bản dịch không dùng được tính là MỘT
         * lần hỏng của riêng nó — các câu khác trong lô vẫn được lưu.
         *
         * Không có nhánh này thì một câu model luôn từ chối sẽ được thử lại mỗi
         * lần có người mở từ đó, mãi mãi.
         */
        $missed = $pending->reject(fn (DictionaryExample $example): bool => in_array($example->id, $written, true));

        if ($missed->isNotEmpty()) {
            Log::info('example-translation: lô thiếu mục', [
                'word_id' => $word->id,
                'expected' => $pending->count(),
                'written' => count($written),
            ]);

            $this->recordFailure($missed);
        }
    }

    /**
     * Những câu ví dụ còn cần dịch của một từ.
     *
     * Dùng chung với controller: nó quyết định trả `pending` hay `unavailable`
     * bằng ĐÚNG tập này. Hai định nghĩa "còn cần dịch" ở hai chỗ là hai định
     * nghĩa có thể lệch nhau, và lệch ở đây nghĩa là FE poll một job không bao
     * giờ được xếp.
     *
     * @return EloquentCollection<int, DictionaryExample>
     */
    public static function pending(DictionaryWord $word): EloquentCollection
    {
        /** @var EloquentCollection<int, DictionaryExample> $pending */
        $pending = $word->examples()
            ->limit(DictionaryExample::MAX_PER_WORD)
            ->get()
            ->filter(fn (DictionaryExample $example): bool => ! ExampleTranslationPrompt::isCurrent($example)
                && $example->vi_attempts < DictionaryExample::MAX_ATTEMPTS)
            ->values();

        return $pending;
    }

    /**
     * @param  EloquentCollection<int, DictionaryExample>  $examples
     */
    private function recordFailure(EloquentCollection $examples): void
    {
        DictionaryExample::query()
            ->whereIn('id', $examples->pluck('id')->all())
            ->increment('vi_attempts');
    }

    /**
     * Giữ nhịp gọi dưới trần RPM cấu hình.
     *
     * Khóa RIÊNG `gemini:examples`, đúng quy ước mà `gemini:glosses` đang giữ.
     * Trần RPM là của tài khoản Google chứ không của từng tính năng, nên dùng
     * chung một khóa sẽ "đúng" hơn về số học — nhưng khi đó lệnh dọn nghĩa chạy
     * nền (115k từ) sẽ bỏ đói lớp dịch mà người dùng đang ngồi chờ. Trần thật
     * vẫn là 429 từ Google, và job đã bám `Retry-After`.
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
            return Redis::throttle('gemini:examples')
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
}
