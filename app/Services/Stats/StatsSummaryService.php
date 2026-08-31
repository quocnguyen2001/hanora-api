<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\User;
use App\Models\UserWord;
use App\Services\Review\ReviewScore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tổng hợp số liệu cho màn Thống kê.
 *
 * ## Hai quy tắc chi phối mọi truy vấn ở đây
 *
 * **1. Mọi phép gộp theo ngày chạy bằng SQL với `AT TIME ZONE`, không gộp trong
 * PHP.** Trộn hai cách là cách chắc chắn để số liệu nhảy lúc 7h sáng mỗi ngày:
 * người ôn lúc 6h sáng giờ VN rơi vào ngày UTC hôm trước.
 *
 * **2. Thống kê tính trên MỌI `review_logs`**, kể cả log của `user_words` đã
 * soft-delete (P11). Người dùng đã thực sự ôn những từ đó; xóa một từ khỏi kho
 * không được phép viết lại lịch sử (red team H5).
 *
 * ## `streak_days` KHÔNG còn ở đây
 *
 * Nó từng là một khoá trong payload này, tính bằng `streakDays()` với luật lỏng
 * hơn: một lượt trả lời bất kỳ là đủ ăn một ngày. Nay chuỗi có luật riêng, chặt
 * hơn, và sống ở `StreakService`.
 *
 * Không giữ lại một bản sao đọc từ service đó, vì controller bọc TOÀN BỘ payload
 * này trong `Cache::remember` 60 giây × 4 range: người dùng thêm từ thứ 5 sẽ
 * thấy chip trên header nói 12 cạnh màn Thống kê nói 11 trong tối đa một phút.
 * Một nguồn, không cache, không lệch — app đọc `GET /streak`.
 */
final class StatsSummaryService
{
    public const RANGES = ['week', 'month', 'year', 'all'];

    public function __construct(
        private readonly ReviewScore $score,
        private readonly string $timezone = 'Asia/Ho_Chi_Minh',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user, string $range): array
    {
        $now = CarbonImmutable::now($this->timezone);
        $from = $this->rangeStart($now, $range);
        $previousFrom = $from === null ? null : $from->sub($from->diffAsCarbonInterval($now));

        return [
            'range' => $range,
            'words_learned' => $this->wordsLearned($user),
            'words_learned_delta_pct' => $this->wordsLearnedDeltaPct($user, $from, $previousFrom),
            'reviews_count' => $this->reviewsCount($user, $from),
            'memory_rate' => $this->memoryRate($user, $from),
            'series' => $this->series($user, $from, $now),
            'distribution' => $this->distribution($user),
        ];
    }

    private function rangeStart(CarbonImmutable $now, string $range): ?CarbonImmutable
    {
        return match ($range) {
            'week' => $now->subDays(6)->startOfDay(),
            'month' => $now->subDays(29)->startOfDay(),
            'year' => $now->subDays(364)->startOfDay(),
            default => null,
        };
    }

    /** Số từ đã vào nhịp ôn ổn định trở lên. */
    private function wordsLearned(User $user): int
    {
        return UserWord::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [UserWord::STATUS_REVIEWING, UserWord::STATUS_MASTERED])
            ->count();
    }

    private function wordsLearnedDeltaPct(User $user, ?CarbonImmutable $from, ?CarbonImmutable $previousFrom): int
    {
        if ($from === null || $previousFrom === null) {
            return 0;
        }

        $current = $this->distinctWordsReviewed($user, $from, null);
        $previous = $this->distinctWordsReviewed($user, $previousFrom, $from);

        if ($previous === 0) {
            return $current > 0 ? 100 : 0;
        }

        return (int) round(($current - $previous) / $previous * 100);
    }

    private function distinctWordsReviewed(User $user, CarbonImmutable $from, ?CarbonImmutable $until): int
    {
        $query = DB::table('review_logs')
            ->where('user_id', $user->id)
            ->where('is_retry', false)
            ->where('answered_at', '>=', $from);

        if ($until !== null) {
            $query->where('answered_at', '<', $until);
        }

        return $query->distinct()->count('user_word_id');
    }

    /**
     * Loại `is_retry` để khớp số thẻ người dùng THỰC SỰ gặp.
     */
    private function reviewsCount(User $user, ?CarbonImmutable $from): int
    {
        return $this->logQuery($user, $from)->count();
    }

    /**
     * `correct / total`, loại `is_retry`.
     *
     * Đếm cả lượt làm lại sẽ khiến người càng chăm sửa lỗi càng bị báo tỉ lệ nhớ
     * thấp — đúng ngược với hành vi ta muốn khuyến khích.
     *
     * Phép chia nằm ở `ReviewScore`, không viết lại ở đây: điểm một phiên ôn
     * dùng đúng công thức này thu hẹp vào một phiên. Hai bản sao của cùng một
     * công thức sẽ trôi khỏi nhau, và lúc đó người dùng thấy điểm phiên 90 cạnh
     * tỉ lệ nhớ 87 mà không cách nào giải thích được.
     */
    private function memoryRate(User $user, ?CarbonImmutable $from): int
    {
        $total = $this->logQuery($user, $from)->count();

        if ($total === 0) {
            return 0;
        }

        $correct = $this->logQuery($user, $from)->where('is_correct', true)->count();

        return $this->score->score($total, $correct);
    }

    /**
     * Số lượt ôn theo từng ngày trong kỳ, dùng cho biểu đồ ở P17.
     *
     * @return list<array{label: string, value: int}>
     */
    private function series(User $user, ?CarbonImmutable $from, CarbonImmutable $now): array
    {
        $start = $from ?? $now->subDays(29)->startOfDay();
        $days = max(1, (int) $start->diffInDays($now) + 1);

        // Kỳ dài thì gộp theo ngày vẫn cho ra hàng trăm điểm — cắt còn 30 điểm
        // cuối, đủ cho biểu đồ mà không bắt FE tự rút gọn.
        $days = min($days, 30);
        $windowStart = $now->subDays($days - 1)->startOfDay();

        $counts = DB::table('review_logs')
            ->where('user_id', $user->id)
            ->where('is_retry', false)
            ->where('answered_at', '>=', $windowStart)
            ->selectRaw('(answered_at AT TIME ZONE ?)::date AS day, count(*) AS total', [$this->timezone])
            ->groupBy('day')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day): array => [
                CarbonImmutable::parse((string) $day)->format('Y-m-d') => (int) $total,
            ])
            ->all();

        $series = [];

        // Điền đủ mọi ngày kể cả ngày không ôn: biểu đồ có khoảng trống sẽ vẽ
        // sai hình dạng của thói quen học.
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = $now->subDays($offset)->format('Y-m-d');

            $series[] = ['label' => $day, 'value' => $counts[$day] ?? 0];
        }

        return $series;
    }

    /**
     * Phần trăm theo trạng thái, gộp `learning`+`reviewing` thành "Đang học"
     * theo đúng bảng map dùng chung ở `UserWord::TAB_STATUSES`.
     *
     * @return array<string, int>
     */
    private function distribution(User $user): array
    {
        $counts = UserWord::query()
            ->where('user_id', $user->id)
            ->selectRaw('status, count(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $counts->sum();

        if ($total === 0) {
            return ['new' => 0, 'learning' => 0, 'mastered' => 0];
        }

        $buckets = [];

        foreach (UserWord::TAB_STATUSES as $tab => $statuses) {
            $inBucket = 0;

            foreach ($statuses as $status) {
                $inBucket += (int) $counts->get($status, 0);
            }

            $buckets[$tab] = (int) round($inBucket / $total * 100);
        }

        return $buckets;
    }

    /**
     * @return Builder
     */
    private function logQuery(User $user, ?CarbonImmutable $from)
    {
        $query = DB::table('review_logs')
            ->where('user_id', $user->id)
            // Lượt làm lại bị loại khỏi MỌI số liệu (red team H3).
            ->where('is_retry', false);

        if ($from !== null) {
            $query->where('answered_at', '>=', $from);
        }

        return $query;
    }
}
