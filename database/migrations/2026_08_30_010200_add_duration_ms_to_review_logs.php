<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_logs', function (Blueprint $table): void {
            /*
             * Thời gian người dùng nghĩ + gõ cho MỘT thẻ, tính bằng mili-giây.
             *
             * CLIENT đo và gửi lên, và điều đó chấp nhận được vì trường này CHỈ
             * để hiển thị: nó không đụng vào điểm, xếp loại hay lịch SRS. Một
             * người tự khai 1ms chỉ đang nói dối chính mình. Mọi con số ẢNH
             * HƯỞNG tới kết quả đều do server quyết (xem `is_retry`).
             *
             * Server KHÔNG tự suy được từ khoảng cách giữa hai `answered_at`:
             * khoảng đó gồm cả thời gian đọc màn phản hồi của thẻ trước, nên nó
             * thổi phồng mọi thẻ trừ thẻ đầu tiên.
             *
             * `nullable` cho log cũ và cho lượt nộp không kèm số đo — thiếu
             * thời gian là chuyện bình thường, không phải lỗi.
             */
            $table->integer('duration_ms')->nullable()->after('answer_raw');
        });
    }

    public function down(): void
    {
        Schema::table('review_logs', function (Blueprint $table): void {
            $table->dropColumn('duration_ms');
        });
    }
};
