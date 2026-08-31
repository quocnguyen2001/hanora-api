<?php

declare(strict_types=1);

namespace App\Services\Streak;

use App\Models\ReviewSession;
use App\Models\User;
use App\Models\UserWord;
use App\Services\Stats\StatsSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Chuỗi ngày: mục tiêu mỗi ngày và số ngày liên tiếp đạt nó.
 *
 * ## Luật đạt mục tiêu — viết ở ĐÂY và chỉ ở đây
 *
 * Trong một ngày (giờ VN), người dùng đạt mục tiêu khi làm MỘT trong hai:
 *   - thêm >= 5 từ mới vào kho, từ bất kỳ nguồn nào;
 *   - có >= 1 phiên ôn đã chốt mang ít nhất một lượt trả lời.
 *
 * Không có bản sao thứ hai của luật này ở đâu khác. `StatsSummaryService` từng
 * giữ một bản (`streakDays()`) và nó đã bị xoá — hai bản sao của một công thức
 * sẽ trôi khỏi nhau, và lúc đó người dùng thấy chip nói 12 cạnh màn Thống kê
 * nói 9 mà không cách nào giải thích.
 *
 * ## Hai điều kiện đọc `review_logs`, không đọc `answered_count`
 *
 * `ReviewSessionManager::finish()` chốt được một phiên KHÔNG có lượt trả lời
 * nào. Nếu điều kiện chỉ là "có phiên `finished_at` trong ngày" thì mở phiên rồi
 * bấm kết thúc ngay là ăn chuỗi miễn phí. Cùng lập luận mà `finish()` đã ghi cho
 * chính nó: log là nguồn sự thật, bộ đếm là số liệu hiển thị.
 *
 * ## Vị từ ngày viết bằng KHOẢNG, không bọc cột
 *
 * `created_at >= ? AND < ?` với hai mốc tính sẵn theo giờ VN, chứ không
 * `(created_at AT TIME ZONE ?)::date = ?`. Bọc cột trong biểu thức làm index
 * `['user_id','created_at','id']` sẵn có trở nên vô dụng — trên một truy vấn
 * chạy ở mỗi lần lưu từ.
 */
final class StreakService
{
    /** Số từ mới trong ngày là đủ để đạt mục tiêu. */
    public const WORDS_GOAL = 5;

    public function __construct(
        private readonly string $timezone = 'Asia/Ho_Chi_Minh',
    ) {}

    /**
     * Ghi nhận rằng `$day` CÓ THỂ đã đạt mục tiêu, và cập nhật chuỗi nếu đúng.
     *
     * Nhận NGÀY chứ không tự lấy hôm nay: `finishStale()` chốt phiên bỏ dở với
     * `finished_at` = lượt trả lời cuối, tức một ngày trong quá khứ. Hook chỉ
     * biết "hôm nay" sẽ bỏ sót đúng những người đóng tab giữa phiên — và họ là
     * số đông, vì thế `finishStale()` mới tồn tại.
     *
     * @return array{current: int, met_today: bool, advanced: bool}
     */
    public function registerActivity(User $user, ?CarbonImmutable $day = null): array
    {
        /*
         * Chuẩn hoá về NGÀY THEO GIỜ VN ngay tại cửa vào. `finished_at` tới đây
         * là một thời điểm ở múi giờ bất kỳ; `startOfDay()` trên nó mà chưa đổi
         * múi giờ sẽ cho ra ngày sai với người ôn lúc 6h sáng hoặc 11h đêm.
         */
        $today = $this->today();
        $day = $day === null
            ? $today
            : $day->setTimezone($this->timezone)->startOfDay();

        $advanced = DB::transaction(function () use ($user, $day): bool {
            /*
             * Khoá dòng `users` trước khi đọc: hai lượt ghi đồng thời (lưu từ
             * thứ 5 và chốt phiên) đều thấy `last_goal_met_on` cũ rồi cùng
             * tăng, cho ra chuỗi +2 trong một ngày.
             */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $lastMet = $locked->last_goal_met_on?->startOfDay();
            $before = $locked->current_streak;

            // Đúng ngày đã ghi — idempotent, gọi bao nhiêu lần cũng thế.
            if ($lastMet !== null && $day->equalTo($lastMet)) {
                $this->syncBack($user, $locked);

                return false;
            }

            if (! $this->hasMetGoal($user, $day)) {
                $this->syncBack($user, $locked);

                return false;
            }

            if ($lastMet !== null && $day->lessThan($lastMet)) {
                /*
                 * Một ngày CŨ HƠN ngày đã ghi, và nó vừa đạt mục tiêu.
                 *
                 * Đây không phải chuyện hiếm: ôn 8 thẻ tối thứ Hai rồi đóng tab,
                 * sáng thứ Ba lưu 5 từ (chuỗi = 1, ghi thứ Ba), tối thứ Ba mở
                 * `/review` → `finishStale()` chốt phiên thứ Hai LÙI ngày. Nguồn
                 * lúc đó nói hai ngày liên tiếp, còn ba cột nói một.
                 *
                 * Ba cột không biểu diễn được "có một ngày cũ hơn vừa được điền
                 * vào", nên đường duy nhất đúng là tính lại từ nguồn cho riêng
                 * người này. Hiếm, và đây cũng CHÍNH LÀ phép tính mà
                 * `hanora:streak-rebuild` chạy — dùng chung một hàm, nên bất biến
                 * "rebuild ≡ giá trị đang lưu" đúng theo cấu trúc chứ không phải
                 * nhờ hai cài đặt tình cờ khớp nhau.
                 */
                $this->writeFromSource($locked);
                $this->syncBack($user, $locked);

                return $locked->current_streak > $before;
            }

            // Còn lại: nối dài chuỗi đang giữ, hoặc mở một chuỗi mới.
            $isAdjacent = $lastMet !== null && $day->equalTo($lastMet->addDay());
            $current = $isAdjacent ? $before + 1 : 1;

            $locked->update([
                'current_streak' => $current,
                'longest_streak' => max($locked->longest_streak, $current),
                'last_goal_met_on' => $day->toDateString(),
            ]);

            $this->syncBack($user, $locked);

            return true;
        });

        /*
         * Xoá cache thống kê SAU khi commit.
         *
         * `streak_days` đã rời khỏi payload đó, nhưng `words_learned` và
         * `reviews_count` cũng vừa đổi bởi chính lượt ghi này — người dùng ôn
         * xong mở tab Thống kê phải thấy số nhúc nhích.
         *
         * Chạy ở MỌI lượt ghi, không chỉ khi chuỗi nhích: hai số kia đổi theo
         * từng từ được lưu, còn chuỗi thì mỗi ngày một lần.
         *
         * `afterCommit` chứ không gọi thẳng: `registerActivity()` chạy được BÊN
         * TRONG transaction của `ReviewSessionManager::start()`, và xoá cache
         * trước khi transaction đó commit là mời một request `/stats/summary`
         * song song điền lại cache bằng dữ liệu CHƯA commit rồi phục vụ nó suốt
         * 60 giây.
         */
        DB::afterCommit(fn () => $this->forgetStatsCache($user));

        return [
            'current' => $this->liveStreak($user, $today),
            'met_today' => $this->metOn($user, $today),
            'advanced' => $advanced,
        ];
    }

    /**
     * Đồng bộ model của caller với dòng vừa khoá.
     *
     * Không có bước này thì nhánh no-op trả về giá trị đọc từ một `User` nạp lúc
     * đầu request: hai lượt `POST /vocabulary` liền nhau (màn học chủ đề gõ
     * nhanh) sẽ khiến lượt thứ hai trả `current: 0` và chip trên header tụt
     * xuống 0 — app ghi thẳng response vào cache nên nó tin con số đó.
     */
    private function syncBack(User $user, User $locked): void
    {
        $user->setRawAttributes($locked->getAttributes(), true);
    }

    /**
     * Tính lại ba cột từ nguồn và ghi vào `$user`.
     *
     * NƠI DUY NHẤT làm phép này. `hanora:streak-rebuild` gọi đúng hàm này, và
     * đường ghi cũng gọi nó ở nhánh ghi lùi — nên bất biến "rebuild ≡ giá trị
     * đang lưu" đúng theo CẤU TRÚC, không phải nhờ hai cài đặt tình cờ khớp.
     *
     * `longest_streak` lấy `max()` với giá trị đang có: kỷ lục là thành tích đã
     * xảy ra, một lệnh dựng lại không được phép hạ nó xuống.
     */
    public function writeFromSource(User $user): void
    {
        [$current, $longest, $lastMet] = $this->fromSource($user);

        $user->update([
            'current_streak' => $current,
            'longest_streak' => max($user->longest_streak, $longest),
            'last_goal_met_on' => $lastMet?->toDateString(),
        ]);
    }

    /**
     * Chuỗi hiện tại, dài nhất, và ngày đạt gần nhất — suy từ nguồn.
     *
     * @return array{int, int, CarbonImmutable|null}
     */
    private function fromSource(User $user): array
    {
        $days = $this->goalDays($user);

        if ($days === []) {
            return [0, 0, null];
        }

        $longest = 1;
        $run = 1;

        for ($i = 1, $n = count($days); $i < $n; $i++) {
            $run = $days[$i]->equalTo($days[$i - 1]->addDay()) ? $run + 1 : 1;
            $longest = max($longest, $run);
        }

        /*
         * `$run` là độ dài dãy kết thúc ở ngày đạt CUỐI CÙNG, không nhất thiết
         * là chuỗi còn sống. Việc nó còn sống hay không do `liveStreak()` quyết
         * lúc ĐỌC — cột giữ số đã ghi, lớp đọc áp luật "hôm nay hoặc hôm qua".
         */
        return [$run, $longest, $days[count($days) - 1]];
    }

    /**
     * Mọi ngày (giờ VN) mà user đạt mục tiêu, tăng dần.
     *
     * Hai truy vấn gộp, không phải một vòng lặp hỏi `hasMetGoal()` từng ngày:
     * một tài khoản dùng hai năm là 730 lượt hỏi × 2 truy vấn.
     *
     * @return list<CarbonImmutable>
     */
    private function goalDays(User $user): array
    {
        // `withTrashed()`: xoá một từ khỏi kho không viết lại lịch sử.
        $wordDays = UserWord::withTrashed()
            ->where('user_id', $user->id)
            ->selectRaw('(created_at AT TIME ZONE ?)::date AS day, count(*) AS total', [$this->timezone])
            ->groupBy('day')
            ->having(DB::raw('count(*)'), '>=', self::WORDS_GOAL)
            ->pluck('day');

        // `whereHas('logs')`: phiên chốt mà không có lượt trả lời nào không tính.
        $sessionDays = ReviewSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('finished_at')
            ->whereHas('logs')
            ->selectRaw('DISTINCT (finished_at AT TIME ZONE ?)::date AS day', [$this->timezone])
            ->pluck('day');

        return $wordDays->merge($sessionDays)
            ->map(fn ($day): string => CarbonImmutable::parse((string) $day)->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $day): CarbonImmutable => CarbonImmutable::parse($day))
            ->all();
    }

    /**
     * Ảnh chụp chuỗi cho `GET /streak`.
     *
     * Lịch 30 ngày chỉ tính khi được hỏi: chip nằm trên `AppHeader` nên nó nạp ở
     * MỌI màn, và nó chỉ cần hai số nguyên.
     *
     * @return array<string, mixed>
     */
    public function snapshot(User $user, bool $withCalendar = false): array
    {
        $today = $this->today();

        $data = [
            'current' => $this->liveStreak($user, $today),
            'longest' => $user->longest_streak,
            'met_today' => $this->metOn($user, $today),
            'today' => [
                'words_added' => $this->wordsAddedOn($user, $today),
                'session_finished' => $this->finishedSessionOn($user, $today),
                'words_goal' => self::WORDS_GOAL,
            ],
        ];

        if ($withCalendar) {
            $data['calendar'] = $this->calendar($user, $today);
        }

        return $data;
    }

    /**
     * Trạng thái chuỗi hiện tại, KHÔNG ghi gì.
     *
     * Dùng cho đường ghi idempotent (chốt lại một phiên đã chốt): hợp đồng nói
     * đường ghi luôn trả `{current, met_today, advanced}`, nên trả `null` là phá
     * hợp đồng ở đúng lúc client đang retry.
     *
     * @return array{current: int, met_today: bool, advanced: bool}
     */
    public function snapshotDelta(User $user): array
    {
        $today = $this->today();

        return [
            'current' => $this->liveStreak($user, $today),
            'met_today' => $this->metOn($user, $today),
            'advanced' => false,
        ];
    }

    /**
     * Phần chuỗi đi kèm `/auth/me`, để chip có số khi ngoại tuyến.
     *
     * Response đó được service worker cache ở bucket dữ liệu cá nhân, và
     * `clearSession()` phía app xoá mọi bucket khi đăng xuất. Hai số nguyên là
     * đủ — KHÔNG thêm lịch vào đây, payload này sống trong cache 7 ngày.
     *
     * @return array{current: int, met_today: bool}
     */
    public function brief(User $user): array
    {
        $today = $this->today();

        return [
            'current' => $this->liveStreak($user, $today),
            'met_today' => $this->metOn($user, $today),
        ];
    }

    /**
     * Mục tiêu của `$day` đã đạt chưa. LUẬT DUY NHẤT — xem docblock của class.
     */
    public function hasMetGoal(User $user, CarbonImmutable $day): bool
    {
        return $this->wordsAddedOn($user, $day) >= self::WORDS_GOAL
            || $this->finishedSessionOn($user, $day);
    }

    /**
     * Chuỗi CÒN SỐNG, khác với chuỗi đã ghi.
     *
     * Chưa học hôm nay không phải là đã đứt chuỗi — ngày vẫn còn chạy. Nhưng
     * lần đạt cuối cũ hơn hôm qua thì chuỗi đã chết, dù cột vẫn giữ số cũ.
     */
    private function liveStreak(User $user, CarbonImmutable $today): int
    {
        $lastMet = $user->last_goal_met_on?->startOfDay();

        if ($lastMet === null) {
            return 0;
        }

        $alive = $lastMet->equalTo($today) || $lastMet->equalTo($today->subDay());

        return $alive ? $user->current_streak : 0;
    }

    private function metOn(User $user, CarbonImmutable $day): bool
    {
        return $user->last_goal_met_on?->startOfDay()->equalTo($day) ?? false;
    }

    /**
     * Số từ mới thêm vào kho trong ngày.
     *
     * `withTrashed()` là bắt buộc: xoá một từ khỏi kho KHÔNG được viết lại lịch
     * sử. Đếm không kèm nó nghĩa là xoá 5 từ hôm sau làm bay ngày hôm trước.
     *
     * Đếm `created_at` nên nhánh khôi phục của `POST /vocabulary` (trả 200,
     * không tạo dòng mới) tự động không tính — xoá rồi lưu lại cùng một từ
     * không cày được chuỗi.
     */
    private function wordsAddedOn(User $user, CarbonImmutable $day): int
    {
        [$from, $until] = $this->dayBounds($day);

        return UserWord::withTrashed()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until)
            ->count();
    }

    /** Có phiên nào chốt trong ngày mà thật sự có lượt trả lời không. */
    private function finishedSessionOn(User $user, CarbonImmutable $day): bool
    {
        [$from, $until] = $this->dayBounds($day);

        return ReviewSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $from)
            ->where('finished_at', '<', $until)
            ->whereHas('logs')
            ->exists();
    }

    /**
     * Ngày nào trong 30 ngày gần nhất đã đạt mục tiêu.
     *
     * Hai truy vấn gộp có CHẶN CỬA SỔ, không phải 30 lần hỏi `hasMetGoal()`.
     *
     * @return list<array{date: string, met: bool}>
     */
    private function calendar(User $user, CarbonImmutable $today, int $days = 30): array
    {
        $from = $today->subDays($days - 1)->startOfDay();
        // Hai mốc theo giờ VN, không phải UTC — tên cũ nói sai điều đó.
        [$windowStart, $windowEnd] = [$from, $today->addDay()->startOfDay()];

        $wordDays = UserWord::withTrashed()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<', $windowEnd)
            ->selectRaw('(created_at AT TIME ZONE ?)::date AS day, count(*) AS total', [$this->timezone])
            ->groupBy('day')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day): array => [
                CarbonImmutable::parse((string) $day)->toDateString() => (int) $total,
            ])
            ->all();

        $sessionDays = ReviewSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $windowStart)
            ->where('finished_at', '<', $windowEnd)
            ->whereHas('logs')
            ->selectRaw('DISTINCT (finished_at AT TIME ZONE ?)::date AS day', [$this->timezone])
            ->pluck('day')
            ->map(fn ($day): string => CarbonImmutable::parse((string) $day)->toDateString())
            ->flip()
            ->all();

        $calendar = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = $today->subDays($offset)->toDateString();

            $calendar[] = [
                'date' => $date,
                'met' => ($wordDays[$date] ?? 0) >= self::WORDS_GOAL || isset($sessionDays[$date]),
            ];
        }

        return $calendar;
    }

    /**
     * Nửa mở `[from, until)` của một ngày theo giờ VN.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function dayBounds(CarbonImmutable $day): array
    {
        $from = $day->setTimezone($this->timezone)->startOfDay();

        return [$from, $from->addDay()];  // nửa mở: [from, from+1 ngày)
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone)->startOfDay();
    }

    private function forgetStatsCache(User $user): void
    {
        foreach (StatsSummaryService::RANGES as $range) {
            Cache::forget("stats.summary.{$user->id}.{$range}");
        }
    }
}
