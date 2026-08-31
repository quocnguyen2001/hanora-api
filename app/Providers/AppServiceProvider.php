<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\Review\ReviewScore;
use App\Services\Review\SrsScheduler;
use App\Services\Stats\StatsSummaryService;
use App\Services\Streak\StreakService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // `SrsScheduler` cố tình không đọc `config()` bên trong để giữ tính
        // thuần; múi giờ nối vào ở đây.
        $this->app->bind(
            SrsScheduler::class,
            fn (): SrsScheduler => new SrsScheduler((string) config('app.timezone')),
        );

        // Cùng lý do: gộp ngày phải theo múi giờ VN, và service giữ tính thuần.
        //
        // `ReviewScore` resolve qua container chứ không `new` tại chỗ: đó là
        // cùng công thức mà điểm phiên ôn dùng, và chỉ được phép có một bản.
        // Cùng lý do: luật chuỗi gộp ngày theo giờ VN, service giữ tính thuần.
        $this->app->bind(
            StreakService::class,
            fn (): StreakService => new StreakService((string) config('app.timezone')),
        );

        $this->app->bind(
            StatsSummaryService::class,
            fn (Application $app): StatsSummaryService => new StatsSummaryService(
                $app->make(ReviewScore::class),
                (string) config('app.timezone'),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->configurePasswordResetLink();
    }

    /**
     * Rate limiter CÓ TÊN cho các endpoint auth công khai.
     *
     * Phải có tên, không được dùng `throttle:5,1` dạng inline. Throttle inline
     * sinh khóa cache từ chữ ký route + IP, mà nhóm `api` đã chạy sẵn một
     * `throttle:60,1` inline trên cùng route đó — hai middleware dùng CHUNG một
     * khóa và mỗi request tăng bộ đếm hai lần. Hệ quả đo được: `throttle:5,1`
     * thực tế khóa người dùng sau 2 lần đăng nhập sai, không phải 5.
     *
     * Limiter có tên sinh khóa từ chính tên limiter, nên mỗi cái một bộ đếm
     * riêng và trần số lần đúng bằng con số khai báo.
     */
    private function configureRateLimiters(): void
    {
        // Đây là bề mặt brute-force, khóa theo IP.
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour(3)->by($request->ip()));
        RateLimiter::for('auth-forgot-password', fn (Request $request) => Limit::perHour(3)->by($request->ip()));
        RateLimiter::for('auth-reset-password', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        /*
         * Nộp bài ôn tập: khóa theo USER, không theo IP — nhiều người học chung
         * một mạng là chuyện bình thường.
         *
         * 120/phút rộng rãi có chủ đích: một phiên 50 từ cộng các lượt làm lại
         * vẫn phải lọt, vì chặn nhầm ở đây là chặn đúng luồng hợp lệ.
         */
        RateLimiter::for(
            'review-answers',
            fn (Request $request) => Limit::perMinute(120)->by((string) $request->user()?->id),
        );

        /*
         * Mở phiên ôn: cũng khóa theo USER, và chặt hơn `review-answers` vì mỗi
         * request tạo một bản ghi. 20/phút rộng hơn nhiều lần thao tác thật —
         * đổi chế độ liên tục cũng không chạm trần.
         */
        RateLimiter::for(
            'review-sessions',
            fn (Request $request) => Limit::perMinute(20)->by((string) $request->user()?->id),
        );

        /*
         * Nhận dạng chữ viết tay: mỗi lần ngừng vẽ là một request ra dịch vụ
         * bên thứ ba. 60/phút đủ cho người vẽ liên tục, và chặn được việc biến
         * endpoint này thành proxy miễn phí cho người khác.
         */
        /*
         * Bỏ qua từ ở màn học chủ đề: khoá theo USER, mỗi request tạo một bản
         * ghi trong `user_skipped_words`.
         *
         * 60/phút rộng gấp sáu lần một phiên 10 thẻ, nên không chạm được bằng
         * thao tác thật; nhưng không có nó thì trần duy nhất là `throttle:60,1`
         * theo IP của nhóm `api`, và một tài khoản hợp lệ có thể bơm bảng này
         * lên hàng triệu dòng.
         */
        /*
         * Tạo chủ đề: 5/GIỜ theo user, chặt hơn hẳn mọi limiter khác.
         *
         * Mỗi request xếp một job tiêu 2-3 lời gọi Gemini. Đây là bề mặt duy
         * nhất mà người dùng cuối kích hoạt được chi tiêu AI, nên nó là bề mặt
         * duy nhất đáng siết tới mức này. Trần theo tài khoản
         * (`Topic::MAX_PER_USER`) chặn lớp còn lại: tích luỹ dài hạn.
         */
        RateLimiter::for(
            'topic-create',
            fn (Request $request) => Limit::perHour(5)->by((string) $request->user()?->id),
        );

        /*
         * Lưu từ vào kho. Chưa từng có limiter riêng, và nay endpoint này còn là
         * đường GHI vào bảng `users` (chuỗi ngày) — mỗi request tạo một dòng và
         * có thể cập nhật ba cột.
         *
         * 30/phút, KHÔNG phải 60: nhóm `api` đã áp `throttle:60,1` cũng khoá
         * theo user id, nên một limiter 60/phút ở đây không bao giờ chạm trước
         * và chỉ là trang trí. 30 vẫn gấp ba một phiên học chủ đề đầy đủ (10
         * lần lưu) mà thật sự chặn được vòng lặp bỏ chạy.
         */
        RateLimiter::for(
            'vocabulary-store',
            fn (Request $request) => Limit::perMinute(30)->by((string) $request->user()?->id),
        );

        RateLimiter::for(
            'topic-skips',
            fn (Request $request) => Limit::perMinute(60)->by((string) $request->user()?->id),
        );

        RateLimiter::for(
            'handwriting',
            fn (Request $request) => Limit::perMinute(60)->by((string) $request->user()?->id),
        );
    }

    /**
     * Link đặt lại mật khẩu phải trỏ về màn của FRONTEND, không phải API.
     *
     * Notification mặc định của Laravel dựng URL từ route tên `password.reset`,
     * mà repo này không có route web nào — không ghi đè thì mail gửi đi kèm một
     * link 404.
     *
     * Production: FE cùng origin với API (D12) nên `FRONTEND_URL` trống là đúng
     * và `APP_URL` được dùng. Dev: FE ở cổng 5173 nên phải khai báo.
     */
    private function configurePasswordResetLink(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

            return $base.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->email,
            ]);
        });
    }
}
