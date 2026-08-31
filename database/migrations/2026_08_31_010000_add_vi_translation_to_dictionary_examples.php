<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dictionary_examples', function (Blueprint $table): void {
            /*
             * Bản dịch tiếng Việt của câu, sinh bằng AI khi có người mở từ.
             *
             * `null` là trạng thái HỢP LỆ, không phải dữ liệu thiếu: câu chưa ai
             * mở tới, hoặc đã cạn lượt thử. Cả hai trường hợp màn chi tiết vẫn
             * đọc được bằng `translation_en` — cùng quy ước mà `han_viet: null`
             * và `examples: []` đang giữ.
             *
             * KHÔNG thay thế `translation_en`. Bản Việt do máy dịch, không có
             * người rà, nên dòng tiếng Anh Tatoeba hiển thị cạnh bên là cơ chế
             * đối chiếu duy nhất người học có.
             */
            $table->text('translation_vi')->nullable();

            /*
             * Phiên bản prompt đã sinh ra bản dịch này.
             *
             * Đây là thứ khiến "dịch lại phần đã cũ" thành một truy vấn thay vì
             * một lần xoá sạch: prompt đổi tới mức bản cũ không dùng được nữa
             * thì tăng `ExampleTranslationPrompt::VERSION`, và đúng những dòng
             * cũ được sinh lại.
             */
            $table->smallInteger('vi_version')->nullable();

            /*
             * Số lần dịch hỏng. Chạm trần thì thôi hẳn.
             *
             * Không có bộ đếm này thì một câu Gemini luôn từ chối sẽ được xếp
             * job lại mỗi lần có người mở từ đó — mãi mãi.
             */
            $table->smallInteger('vi_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('dictionary_examples', function (Blueprint $table): void {
            $table->dropColumn(['translation_vi', 'vi_version', 'vi_attempts']);
        });
    }
};
