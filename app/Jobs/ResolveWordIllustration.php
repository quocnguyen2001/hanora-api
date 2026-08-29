<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DictionaryWord;
use App\Models\DictionaryWordIllustration;
use App\Services\Illustration\IllustrationSelector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Tìm và lưu ảnh minh hoạ cho một mục từ.
 *
 * `ShouldBeUnique` là BẮT BUỘC, không phải tối ưu: không có nó, 50 người cùng
 * mở một từ đang hot sẽ đẩy 50 job giống hệt nhau vào hàng đợi và tiêu 100 lời
 * gọi Pixabay cho đúng một kết quả.
 */
final class ResolveWordIllustration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Dài hơn hẳn hai lần trần 8 giây của client, chừa chỗ cho throttle và một
     * lượt thử lại. Job phải luôn sống lâu hơn các lời gọi mà nó bọc; ngược lại
     * job chết giữa chừng và bản ghi kẹt ở `pending` mãi.
     */
    public int $timeout = 60;

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

    public function handle(IllustrationSelector $selector): void
    {
        $word = DictionaryWord::query()->with('illustration')->find($this->wordId);

        if ($word === null) {
            return;
        }

        $illustration = $word->illustration;

        /*
         * Thoát sớm khi đã có kết luận ở đúng phiên bản cổng.
         *
         * Đây là chốt chặn THỨ HAI cho "lần mở thứ hai không gọi API", độc lập
         * với cache HTTP: job xếp lại do retry, do người dùng bấm F5, hay do
         * người khác mở cùng một từ đều dừng ở đây.
         *
         * `none` nằm cùng nhánh với `ready` vì nó là kết luận VĨNH VIỄN chứ
         * không phải một lần thử hỏng — `的` không cần hỏi lại Pixabay bao giờ.
         */
        if ($illustration !== null
            && in_array($illustration->status, [
                DictionaryWordIllustration::STATUS_READY,
                DictionaryWordIllustration::STATUS_NONE,
            ], true)
            && $illustration->gate_version === IllustrationSelector::GATE_VERSION) {
            return;
        }

        if ($illustration !== null
            && $illustration->attempts >= DictionaryWordIllustration::MAX_ATTEMPTS) {
            return;
        }

        $selection = $this->throttled(fn () => $selector->select($word));

        if ($selection === null) {
            // Không lấy được lượt trong hàng đợi rate limit — thử lại sau, và
            // KHÔNG tính là một lần thất bại của từ này.
            $this->release(30);

            return;
        }

        if ($selection->throttled) {
            // 429 là tín hiệu nhịp độ, không phải lỗi của từ này. `attempts`
            // giữ nguyên, nếu không một đợt chạm trần sẽ đánh dấu `failed` hàng
            // loạt từ hoàn toàn bình thường.
            $this->release($selection->retryAfter);

            return;
        }

        if ($selection->gateClosed) {
            $this->recordNone($word);

            return;
        }

        if ($selection->candidate === null) {
            $this->recordFailure($word, $selection->reason ?? 'api_error');

            return;
        }

        $candidate = $selection->candidate;

        DictionaryWordIllustration::query()->updateOrCreate(
            ['word_id' => $word->id],
            [
                'status' => DictionaryWordIllustration::STATUS_READY,
                'image_url' => $candidate->imageUrl,
                'preview_url' => $candidate->previewUrl,
                'page_url' => $candidate->pageUrl,
                'author' => $candidate->author,
                'author_url' => $candidate->authorUrl,
                'source_id' => $candidate->sourceId,
                'width' => $candidate->width,
                'height' => $candidate->height,
                'matched_query' => $candidate->matchedQuery,
                'gate_version' => IllustrationSelector::GATE_VERSION,
                'failed_reason' => null,
                'resolved_at' => now(),
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
        $rpm = max(1, (int) config('services.pixabay.rpm', 60));

        try {
            return Redis::throttle('pixabay:resolve')
                ->allow($rpm)
                ->every(60)
                ->block(5)
                ->then($callback, fn () => null);
        } catch (Throwable) {
            /*
             * Redis không có hoặc `throttle` không dùng được (ví dụ test chạy
             * driver khác). Van giảm áp KHÔNG được phép là lý do tính năng chết
             * — trần thật vẫn là 429 từ Pixabay.
             */
            return $callback();
        }
    }

    /**
     * Ghi lại "từ này đúng ra không có ảnh".
     *
     * KHÔNG tăng `attempts`: đây là một kết luận THÀNH CÔNG. Tăng bộ đếm thất
     * bại ở đây sẽ khiến `的` và mọi hư từ khác trông như đang hỏng.
     */
    private function recordNone(DictionaryWord $word): void
    {
        DictionaryWordIllustration::query()->updateOrCreate(
            ['word_id' => $word->id],
            [
                'status' => DictionaryWordIllustration::STATUS_NONE,
                'image_url' => null,
                'preview_url' => null,
                'page_url' => null,
                'author' => null,
                'author_url' => null,
                'source_id' => null,
                'width' => null,
                'height' => null,
                'gate_version' => IllustrationSelector::GATE_VERSION,
                'failed_reason' => null,
                'resolved_at' => now(),
            ],
        );
    }

    /**
     * KHÔNG đặt tên `fail()`: trait `Queueable` đã có `fail()` để đánh dấu job
     * hỏng vĩnh viễn. Trùng tên ở đây sẽ ghi đè nó và biến mọi lần "từ này tìm
     * ảnh không được" thành "job này chết", mất luôn cơ chế retry.
     */
    private function recordFailure(DictionaryWord $word, string $reason): void
    {
        $illustration = DictionaryWordIllustration::query()
            ->firstOrNew(['word_id' => $word->id]);

        $illustration->fill([
            'status' => DictionaryWordIllustration::STATUS_FAILED,
            'failed_reason' => $reason,
        ]);

        // `attempts` ngoài `$fillable` có chủ đích: nó là bộ đếm nội bộ.
        $illustration->attempts = $illustration->attempts + 1;
        $illustration->save();

        Log::warning('illustration: tìm ảnh thất bại', [
            'word_id' => $word->id,
            'reason' => $reason,
            'attempts' => $illustration->attempts,
        ]);
    }
}
