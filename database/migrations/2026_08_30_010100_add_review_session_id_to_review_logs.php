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
             * NULLABLE là để chứa QUÁ KHỨ, không phải để cho phép tương lai
             * thiếu: log sinh ra trước migration này không thuộc phiên nào.
             * `SubmitAnswerRequest` bắt buộc trường này cho mọi lượt nộp mới.
             *
             * `restrictOnDelete` khớp quy ước của `user_word_id` cùng bảng: log
             * không bao giờ biến mất theo một bản ghi khác. Nó cũng là lưới an
             * toàn cho `finishStale()` — hàm đó chỉ được xoá phiên KHÔNG có
             * lượt nào, và FK sẽ chặn nếu logic đó sai.
             */
            $table->foreignId('review_session_id')
                ->nullable()
                ->after('user_word_id')
                ->constrained('review_sessions')
                ->restrictOnDelete();

            /*
             * Phục vụ HAI đường đọc:
             *   1. chi tiết một phiên — `WHERE review_session_id = ?`
             *   2. suy `is_retry` ở server — thêm `AND user_word_id = ?`
             *
             * Đường (2) chạy mỗi lượt nộp bài. Một phiên tối đa 50 thẻ nên lọc
             * `user_word_id` sau khi thu hẹp theo phiên là đủ rẻ; không cần
             * index thứ hai.
             */
            $table->index(['review_session_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::table('review_logs', function (Blueprint $table): void {
            $table->dropIndex(['review_session_id', 'answered_at']);
            $table->dropConstrainedForeignId('review_session_id');
        });
    }
};
