<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Từ thuộc chủ đề. Dữ liệu PHÁI SINH: dựng lại được bất cứ lúc nào bằng
     * `topics:import` từ `database/data/topics/*.json` đã commit.
     */
    public function up(): void
    {
        Schema::create('topic_words', function (Blueprint $table): void {
            $table->id();

            /*
             * CASCADE cả hai khoá, KHÁC `user_words` vốn dùng `restrictOnDelete`
             * trên `word_id`.
             *
             * Khác có chủ đích: `user_words` là dữ liệu người dùng, mất là mất
             * hẳn. Bảng này là bản dựng lại được từ file trong git, nên restrict
             * ở đây chỉ tạo ra một migration bị chặn mà không bảo vệ gì.
             */
            $table->foreignId('topic_id')->constrained('topics')->cascadeOnDelete();
            $table->foreignId('word_id')->constrained('dictionary_words')->cascadeOnDelete();

            /*
             * Thứ tự trong chủ đề, theo `frequency_rank` tăng dần.
             *
             * Màn học bốc ngẫu nhiên trong LÁT CẮT đầu của cột này, không bốc
             * đều trên cả bộ — bốc đều sẽ trả `凝聚` ngang hàng `爱`. Sai thứ tự
             * ở đây là dạy đuôi dài trước.
             */
            $table->smallInteger('rank');

            /*
             * Vòng sinh ra dòng này (1, 2, hoặc 3).
             *
             * Là thứ DUY NHẤT trả lời được "vì sao từ này nằm trong chủ đề đó"
             * khi cần chỉnh cổng chặn — cùng vai trò mà
             * `dictionary_word_illustrations.matched_query` đang giữ.
             */
            $table->smallInteger('generated_batch');

            $table->timestampsTz();

            $table->unique(['topic_id', 'word_id']);

            // Truy vấn nóng duy nhất: lấy cả bộ của một chủ đề, sắp theo rank.
            $table->index(['topic_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_words');
    }
};
