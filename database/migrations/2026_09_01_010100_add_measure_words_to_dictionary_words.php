<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Lượng từ, tách khỏi `definitions_en`.
     *
     * CC-CEDICT mã hoá chúng bằng `CL:` ngay trong phần nghĩa, nên trước
     * migration này chuỗi `CL:家[jia1],個|个[ge4]` nằm nguyên trong cột nghĩa và
     * hiện dạng mã cho người dùng ở cả thẻ tìm kiếm lẫn màn chi tiết.
     *
     * `ADD COLUMN` nullable không có DEFAULT nên PostgreSQL chỉ sửa catalog,
     * không viết lại heap — khác hẳn migration cột gloss vốn phải đặt
     * `lock_timeout` vì nó là `ALTER TABLE ... GENERATED` trên bảng 92 MB.
     *
     * KHÔNG index: không đường tra cứu nào đi qua lượng từ. Nó chỉ được đọc
     * cùng mục từ đã tìm thấy bằng khoá khác.
     */
    public function up(): void
    {
        Schema::table('dictionary_words', function (Blueprint $table): void {
            /*
             * `null` = "từ này không có lượng từ", một trạng thái HỢP LỆ và
             * thường gặp — cùng quy ước mà `definitions_vi` và `han_viet` giữ.
             * Tầng resource chuẩn hoá nó thành `[]` cho FE, nên app không phải
             * mang hai nhánh cho cùng một ý.
             */
            $table->jsonb('measure_words')->nullable()->after('definitions_vi');
        });
    }

    public function down(): void
    {
        Schema::table('dictionary_words', function (Blueprint $table): void {
            $table->dropColumn('measure_words');
        });
    }
};
