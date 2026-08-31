<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Topic;
use App\Services\Topic\TopicGenerationOutcome;
use App\Services\Topic\TopicGenerator;
use App\Services\Topic\TopicWordResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sinh bộ từ cho một chủ đề do người dùng tự tạo.
 *
 * Đây là chỗ DUY NHẤT trong production gọi Gemini, và nó cố ý nằm ở đây chứ
 * không ở controller: một vòng sinh mất 16-25 giây (2-3 lời gọi × ~8s), tức là
 * giữ một worker PHP-FPM suốt thời gian đó nếu chạy đồng bộ. Endpoint chỉ
 * dispatch và trả `202`.
 *
 * KHÔNG ghi ra JSON như `topics:generate`: JSON tồn tại để phục vụ bước rà bằng
 * mắt, mà chủ đề tự tạo không có bước đó (đổi lại bằng phạm vi cá nhân).
 */
final class GenerateUserTopic implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * MỘT lần thử.
     *
     * `TopicGenerator` đã tự xử lý 429 bằng `Sleep` + thử lại bên trong, nên
     * thử lại ở tầng job chỉ đốt thêm quota cho một câu trả lời đã biết là
     * hỏng — cùng lập luận `retry: false` mà lớp ảnh minh hoạ đã ghi.
     */
    public int $tries = 1;

    /** 2-3 vòng × ~8s, cộng đệm cho mạng chậm. */
    public int $timeout = 180;

    public function __construct(public int $topicId)
    {
        /*
         * Queue `enrichment`, KHÔNG phải `default`.
         *
         * `default` là nơi mail đặt lại mật khẩu chạy — ở đó có người đang chờ
         * để ĐĂNG NHẬP LẠI, ưu tiên cao hơn một chủ đề mới. README chốt thứ tự
         * ưu tiên: default → enrichment → glosses.
         *
         * Đặt trong constructor chứ không để chỗ dispatch tự nhớ: một lời gọi
         * `dispatch()` quên `->onQueue()` sẽ im lặng rơi về `default` — đúng
         * lỗi vừa gặp khi chạy thử.
         */
        $this->onQueue('enrichment');
    }

    /** Khoá theo chủ đề: bấm kép không được đẻ hai job cùng đốt quota. */
    public function uniqueId(): string
    {
        return (string) $this->topicId;
    }

    public function handle(TopicGenerator $generator, TopicWordResolver $resolver): void
    {
        $topic = Topic::query()->find($this->topicId);

        if (! $topic instanceof Topic || $topic->status !== Topic::STATUS_GENERATING) {
            // Người dùng đã xoá chủ đề trong lúc job xếp hàng — không phải lỗi.
            return;
        }

        $result = $generator->generate($topic->slug, (string) $topic->prompt_term);

        if (! $result->isWritable()) {
            $this->markFailed($topic, $result->outcome === TopicGenerationOutcome::Throttled
                ? 'rate_limited'
                : 'generation_failed');

            return;
        }

        $rows = [];

        foreach ($result->words as $word) {
            $resolution = $resolver->resolveExact($word['zh'], $word['pinyin']);

            if (! $resolution->isAccepted()) {
                continue;
            }

            $rows[] = [
                'topic_id' => $topic->id,
                'word_id' => $resolution->word->id,
                'rank' => $word['rank'],
                'generated_batch' => $word['batch'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        /*
         * Bộ từ rỗng là THẤT BẠI, không phải "chủ đề không có từ nào".
         *
         * Để nó thành `ready` với 0 từ sẽ cho ra một thẻ `0/0` bấm vào là màn
         * "đã học hết" ngay lập tức — người dùng không hiểu chuyện gì xảy ra và
         * cũng không biết là nên xoá đi tạo lại.
         */
        if ($rows === []) {
            $this->markFailed($topic, 'no_words');

            return;
        }

        DB::transaction(function () use ($topic, $rows): void {
            DB::table('topic_words')->where('topic_id', $topic->id)->delete();
            DB::table('topic_words')->insert($rows);

            $topic->update(['status' => Topic::STATUS_READY, 'failed_reason' => null]);
        });
    }

    /**
     * Job chết vì exception hoặc timeout.
     *
     * Không có hàm này thì chủ đề treo ở `generating` vĩnh viễn: app poll mãi,
     * và suất trong trần 20 bị giữ bởi một thứ không bao giờ xong.
     */
    public function failed(?Throwable $exception): void
    {
        $topic = Topic::query()->find($this->topicId);

        if ($topic instanceof Topic && $topic->status === Topic::STATUS_GENERATING) {
            $this->markFailed($topic, 'job_failed');
        }
    }

    private function markFailed(Topic $topic, string $reason): void
    {
        $topic->update(['status' => Topic::STATUS_FAILED, 'failed_reason' => $reason]);
    }
}
