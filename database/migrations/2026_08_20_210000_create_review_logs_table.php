<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * KHÔNG cascade theo `user_words`.
             *
             * `user_words` dùng soft delete (P11) chính là để log ở đây sống
             * sót: P16 tính streak và tỉ lệ nhớ trên MỌI log, kể cả của từ đã bị
             * bỏ khỏi kho — người dùng đã thực sự ôn chúng.
             */
            $table->foreignId('user_word_id')->constrained('user_words')->restrictOnDelete();

            $table->string('mode', 16);
            $table->boolean('is_correct');

            /*
             * Lượt làm lại trong cùng phiên.
             *
             * Được GHI LẠI để phân tích, nhưng KHÔNG chạy scheduler và bị loại
             * khỏi `memory_rate` cùng `reviews_count` ở P16 (red team H3). Nếu
             * không tách: sai rồi sửa ngay sẽ cho ra cùng lịch như đúng ngay từ
             * đầu — hình phạt SRS bị xóa sạch — và tỉ lệ nhớ đếm cả hai lượt.
             */
            $table->boolean('is_retry')->default(false);

            /*
             * 64 ký tự, khớp trần của FormRequest.
             *
             * Không giới hạn thì một chuỗi 1MB lặp lại đủ để lấp đĩa VPS, kéo
             * theo Postgres và cả `pg_dump` hằng đêm.
             */
            $table->string('answer_raw', 64)->nullable();

            $table->integer('interval_before');
            $table->integer('interval_after');

            $table->timestampTz('answered_at');
            $table->timestampsTz();

            // P16 quét theo khoảng thời gian của từng user.
            $table->index(['user_id', 'answered_at']);
            $table->index(['user_word_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_logs');
    }
};
