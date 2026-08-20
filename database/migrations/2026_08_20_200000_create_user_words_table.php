<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Kho từ cá nhân.
     *
     * Các cột SRS (`interval_days`, `ease_factor`, `repetitions`,
     * `next_review_at`…) khai báo NGAY Ở ĐÂY dù P14 mới dùng: thêm cột vào bảng
     * đã có dữ liệu thật của người dùng là một lần migration rủi ro không ai
     * cần, và bảng này sẽ có dữ liệu thật ngay khi P12 lên.
     */
    public function up(): void
    {
        Schema::create('user_words', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * RESTRICT, không phải cascade: re-import từ điển KHÔNG được phép
             * xóa mục mà người dùng đang lưu. Nếu upstream bỏ một mục thì giữ
             * lại và đánh dấu, chứ không xóa dữ liệu của người dùng theo.
             */
            $table->foreignId('word_id')->constrained('dictionary_words')->restrictOnDelete();

            $table->string('status', 16)->default('new');

            // P14 sở hữu vòng đời của nhóm cột này.
            $table->integer('interval_days')->default(0);
            $table->decimal('ease_factor', 4, 2)->default(2.50);
            $table->integer('repetitions')->default(0);
            $table->integer('review_count')->default(0);
            $table->integer('correct_count')->default(0);
            $table->timestampTz('next_review_at')->nullable();
            $table->timestampTz('last_reviewed_at')->nullable();

            /*
             * SOFT DELETE, và đây là quyết định một chiều.
             *
             * `review_logs` (P14) tham chiếu bảng này. Xóa cứng dẫn tới một
             * trong hai kết cục, cả hai đều tệ: FK violation làm optimistic
             * remove của P12 rollback bằng một lỗi kỹ thuật người dùng không
             * hiểu; hoặc cascade xóa sạch log của từ đó. Kết cục thứ hai nguy
             * hiểm hơn vì nó im lặng — `streak_days` ở P16 tính từ log còn sót,
             * nên xóa một từ cũ có thể làm bay chuỗi 60 ngày, không giải thích
             * được và không khôi phục được.
             */
            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index(['user_id', 'next_review_at']);
            $table->index(['user_id', 'status']);
            // Keyset pagination chạy trên cặp này.
            $table->index(['user_id', 'created_at', 'id']);
        });

        /*
         * Unique CHỈ trên bản ghi còn sống: xóa rồi lưu lại cùng một từ phải
         * khôi phục bản ghi cũ (giữ nguyên lịch sử ôn), và unique thường sẽ
         * chặn cả bản ghi đã soft-delete.
         */
        DB::statement(
            'CREATE UNIQUE INDEX user_words_user_word_alive
             ON user_words (user_id, word_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('user_words');
    }
};
