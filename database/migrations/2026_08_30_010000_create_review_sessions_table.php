<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Một phiên ôn tập.
     *
     * Trước bảng này, điểm tổng kết chỉ sống trong state React và mất khi rời
     * trang — không có thực thể nào để gắn nó vào. Đây là mảnh duy nhất còn
     * thiếu để chấm điểm và lịch sử phiên tồn tại được; `review_logs` đã ghi đủ
     * từng lượt trả lời từ P14.
     */
    public function up(): void
    {
        Schema::create('review_sessions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('mode', 16);

            // `due` = từ tới hạn theo lịch SRS; `weak` = từ hay sai, bỏ qua lịch.
            $table->string('source', 16)->default('due');

            // Số thẻ THỰC SỰ phát ra, không phải `limit` người dùng chọn: mode
            // trắc nghiệm loại bỏ mục không dựng đủ 4 lựa chọn.
            $table->integer('planned_count');

            /*
             * Bộ đếm CHỈ tăng ở lượt đầu (`is_retry = false`), khớp quy ước của
             * `user_words.review_count`.
             *
             * Chúng phục vụ HIỂN THỊ TIẾN ĐỘ, không phải nguồn tính điểm:
             * `finish()` đếm lại trên `review_logs`. Nếu tính điểm từ đây thì
             * một lượt nộp commit sau khi phiên đã chốt sẽ để lại một `score`
             * không khớp chính bộ đếm của nó, và `finish()` idempotent nên
             * không bao giờ tính lại.
             */
            $table->integer('answered_count')->default(0);
            $table->integer('correct_count')->default(0);

            $table->smallInteger('score')->nullable();

            // NULL khi `answered_count = 0`: một phiên chưa trả lời câu nào
            // không phải "cần ôn thêm", nó không có xếp loại.
            $table->string('grade', 16)->nullable();

            $table->timestampTz('started_at');

            // NULL = đang mở.
            $table->timestampTz('finished_at')->nullable();

            $table->timestampsTz();

            // Lịch sử phiên đọc theo chiều giảm dần trên cặp này.
            $table->index(['user_id', 'started_at']);
        });

        /*
         * KHÔNG có cột `duration_seconds`: `finished_at - started_at` tính được,
         * thêm cột là nhân bản sự thật. Resource trả nó như trường tính toán —
         * và phải theo chiều `started->finished`, vì Carbon 3 trả diff CÓ DẤU
         * nên chiều ngược lại cho ra số âm.
         *
         * KHÔNG có partial unique index trên `(user_id) WHERE finished_at IS NULL`.
         * Bất biến "mỗi user tối đa một phiên mở" được giữ bằng
         * `pg_advisory_xact_lock(user_id)` trong `ReviewSessionManager::start()`.
         * Transaction một mình KHÔNG đủ — ở READ COMMITTED hai `start()` đồng
         * thời đều thấy 0 phiên mở rồi đều INSERT. Nhưng unique index cũng
         * không phải câu trả lời: nó biến một race thành lỗi 500 người dùng
         * không hiểu, còn advisory lock thì tuần tự hoá nó một cách im lặng.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('review_sessions');
    }
};
