<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Nghĩa tiếng Việt trực tiếp từ CVDICT, thay cho cầu nối hai chặng.
     *
     * Hai cột NULLABLE, không DEFAULT: độ phủ là 93%, nên ~8.600 dòng sẽ không
     * có nghĩa tiếng Việt và đó là hành vi HỢP LỆ, không phải lỗi. `null` phân
     * biệt được với mảng rỗng — mảng rỗng nghĩa là "đã khớp nhưng không có
     * nghĩa nào", trạng thái không tồn tại ở nguồn này.
     *
     * Nullable + không DEFAULT cũng là lý do `ALTER TABLE` này không rewrite
     * bảng 123k dòng: Postgres chỉ sửa catalog, khóa ACCESS EXCLUSIVE vài mili
     * giây.
     *
     * Cột tsvector KHÔNG nằm ở đây — chúng thuộc migration của phase sau, nơi
     * có truy vấn thật sự đọc chúng.
     */
    public function up(): void
    {
        Schema::table('dictionary_words', function (Blueprint $table): void {
            // jsonb giữ THỨ TỰ nghĩa. Không phải trang trí: xếp hạng tìm kiếm
            // phân biệt "khớp trong nghĩa ĐẦU" với "khớp bất kỳ đâu", và CVDICT
            // liệt kê nghĩa chính trước.
            $table->jsonb('definitions_vi')->nullable();

            // Bản phẳng `; ` — nguồn cho tsvector ở phase sau, cùng khuôn với
            // `definitions_en_text`.
            $table->text('definitions_vi_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dictionary_words', function (Blueprint $table): void {
            $table->dropColumn(['definitions_vi', 'definitions_vi_text']);
        });
    }
};
