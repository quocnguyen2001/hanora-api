<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Chuỗi ngày, materialize thành ba cột thay vì suy ra mỗi lần đọc.
     *
     * Suy ra khi đọc là cách `StatsSummaryService::streakDays()` đang làm, và nó
     * đủ cho một con số trong màn Thống kê. Nó KHÔNG đủ cho chip trên header
     * (nạp ở mọi màn) và cho vòng quét gửi nhắc nhở sau này, thứ phải trả lời
     * "ai đang giữ chuỗi mà chưa đạt mục tiêu hôm nay" cho TOÀN BỘ người dùng.
     *
     * Giá phải trả là dữ liệu materialize thì lệch được với nguồn. Trả bằng
     * `hanora:streak-rebuild` — dựng lại cả ba cột từ `review_sessions` +
     * `user_words` bất cứ lúc nào.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);

            /*
             * `date`, không phải timestamp: đây là một NGÀY theo giờ VN, không
             * phải một thời điểm. Lưu timestamp ở đây là mời người sau so sánh
             * nó với `now()` và làm chuỗi reset lúc 7h sáng.
             *
             * KHÔNG đánh index cột này. Vị từ dùng nó là bất đẳng thức
             * (`<> hôm nay`) khớp gần như toàn bảng, nên Postgres sẽ không chọn
             * B-tree; index chỉ còn là chi phí ghi thuần trên một cột được
             * UPDATE mỗi ngày cho mỗi người dùng.
             */
            $table->date('last_goal_met_on')->nullable();
        });

        /*
         * Luật "đã ôn hôm nay" đọc `finished_at`, nhưng bảng chỉ có index trên
         * `started_at`. Thiếu dòng này thì mỗi lần kiểm mục tiêu là một lần quét
         * tuần tự phần phiên của user đó.
         */
        Schema::table('review_sessions', function (Blueprint $table): void {
            $table->index(['user_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::table('review_sessions', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'finished_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['current_streak', 'longest_streak', 'last_goal_met_on']);
        });
    }
};
