<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Cache diễn giải truy vấn bằng AI.
     *
     * Chi phí của lớp search KHÔNG có trần tự nhiên: lớp làm giàu gọi 123.646
     * lần rồi thôi, còn ở đây mỗi truy vấn MỚI là một lời gọi, mãi mãi. Bảng
     * này là thứ duy nhất biến chi phí đó thành hữu hạn.
     *
     * Đo 2026-08-28: một lời gọi diễn giải mất 3,2-3,8 giây và ~$0,0004. Trúng
     * cache đưa cả hai con số về gần không.
     */
    public function up(): void
    {
        Schema::create('search_query_interpretations', function (Blueprint $table): void {
            $table->id();

            /*
             * 200 ký tự. Dài hơn thế không phải truy vấn tra từ, và để nguyên
             * thì một chuỗi dán nhầm sẽ vỡ trần 2704 byte của index btree.
             */
            $table->string('query_normalized', 200);
            $table->string('mode', 8);

            /*
             * Lưu ID chứ không lưu chữ Hán.
             *
             * Tra ngược corpus đã chạy một lần lúc ghi; lưu chữ Hán nghĩa là mỗi
             * lần trúng cache lại phải tra lại đúng việc đó. ID cũng khiến bản
             * ghi hỏng một cách RÕ RÀNG nếu mục từ bị xóa, thay vì im lặng trả
             * về một chữ mồ côi không còn tra được.
             *
             * Mảng RỖNG là giá trị hợp lệ: truy vấn vô nghĩa được hỏi đúng một
             * lần rồi thôi. Không cache kết quả rỗng thì mỗi lần ai đó gõ nhầm
             * lại là 3,5 giây và một khoản tiền.
             */
            $table->jsonb('word_ids');

            $table->string('model', 64);
            $table->smallInteger('prompt_version')->default(1);

            // Đủ để suy ra tỉ lệ trúng cache mà không cần dựng bảng log riêng.
            $table->integer('hit_count')->default(0);

            $table->timestampsTz();

            // Cùng một truy vấn ở `vi` và `cn` là hai ý định khác nhau.
            $table->unique(['query_normalized', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_query_interpretations');
    }
};
