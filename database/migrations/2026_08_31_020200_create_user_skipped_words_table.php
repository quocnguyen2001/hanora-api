<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Từ người dùng đã bấm "Đã biết rồi" ở màn học chủ đề.
     *
     * Đây là state người dùng MỚI duy nhất của tính năng chủ đề, nên nó theo
     * đúng kỷ luật của `user_words`, không phải của `topic_words`.
     */
    public function up(): void
    {
        Schema::create('user_skipped_words', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * RESTRICT, không phải cascade — cùng lý do đã ghi ở `user_words`.
             *
             * Đây là quyết định của con người và KHÔNG dựng lại được. Cascade sẽ
             * xoá âm thầm khi một mục CC-CEDICT biến mất ở lần re-import; nếu
             * mục đó quay lại với `id` mới (id sinh lúc import, không ổn định)
             * thì từ người dùng đã chủ động từ chối sẽ xuất hiện lại trong phiên
             * học, không lỗi, không log.
             */
            $table->foreignId('word_id')->constrained('dictionary_words')->restrictOnDelete();

            $table->timestampsTz();

            /*
             * Bỏ qua là TOÀN CỤC, không theo chủ đề.
             *
             * "Đã biết rồi" là phát biểu về CÁI TỪ, không phải về chủ đề. `喜欢`
             * nằm trong cả "tình yêu" lẫn "gia đình", và bắt người dùng bỏ qua
             * nó hai lần là hỏi lại một câu họ đã trả lời.
             */
            $table->unique(['user_id', 'word_id']);

            // `GET /topics/skips` quét theo user; `/topics` join theo cặp này.
            $table->index('user_id');
        });

        /*
         * KHÔNG có `softDeletes`, khác `user_words`.
         *
         * Ở đó soft delete tồn tại vì `review_logs` tham chiếu tới và xoá cứng
         * sẽ làm bay lịch sử ôn. Ở đây không bảng nào tham chiếu tới và không có
         * lịch sử nào để giữ — bỏ bỏ-qua là xoá dòng.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('user_skipped_words');
    }
};
