<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Dữ liệu Hán tự TẤT ĐỊNH, nhập từ Make Me a Hanzi.
     *
     * Tồn tại vì lớp làm giàu AI từng được hỏi bộ thủ và số nét — đúng chỗ model
     * bịa nhiều nhất, và `EnrichmentValidator` không tra ngược được. Bảng này là
     * nguồn thay thế; prompt sẽ thôi xin hai trường đó.
     *
     * 9.574 chữ, không đổi theo thời gian, không job nền, không trạng thái
     * `pending`. Đây là điểm khác biệt lớn nhất so với ba lớp lười đang có
     * (ảnh minh hoạ, dịch câu ví dụ, làm giàu).
     */
    public function up(): void
    {
        Schema::create('dictionary_characters', function (Blueprint $table): void {
            $table->id();

            /*
             * Khoá TỰ NHIÊN là `char`, và mọi đường tra đều đi qua nó. `id` giữ
             * lại chỉ vì tiện cho Eloquent; không route nào dùng tới.
             */
            $table->string('char', 8)->unique();

            /*
             * Bộ thủ giữ nguyên BIẾN THỂ mà nguồn ghi (`刂` chứ không quy về
             * `刀`). Đó là hình dạng thật xuất hiện trong chữ, và đo được là
             * 244/295 biến thể vẫn tra ra âm Hán-Việt từ chính `dictionary_words`
             * — nên quy về dạng chuẩn không mua thêm được gì mà lại làm sai hình.
             */
            $table->string('radical', 8)->nullable();
            $table->string('radical_han_viet', 32)->nullable();

            /*
             * `null` khi chữ có trong `dictionary.txt` nhưng vắng ở
             * `graphics.txt`. Nullable chứ không mặc định 0: "0 nét" là một lời
             * khẳng định sai, còn `null` là "không biết" và FE ẩn dòng.
             */
            $table->smallInteger('stroke_count')->nullable();

            /*
             * Hình thái, dạng IDS: `⿰佥刂`.
             *
             * Nguồn dùng `？` (dấu hỏi TOÀN RỘNG) cho 66 chữ nó không phân tích
             * được. Importer phải quy giá trị đó về `null`; để nguyên thì màn
             * hình hiện "Hình thái: ？".
             */
            $table->string('decomposition', 64)->nullable();

            /*
             * Lục thư — nhưng nguồn chỉ có BA loại, không phải sáu:
             * pictophonetic (6.966), ideographic (1.840), pictographic (227).
             * Hội ý, chuyển chú, giả tá không có. 541 chữ không có trường này.
             */
            $table->string('etymology_type', 16)->nullable();

            /*
             * Dãy hình nét theo thứ tự viết: `["丿","丶","一",…]`.
             *
             * Sinh bằng `scripts/generate-stroke-names.mjs` (cnchar, MIT) chứ
             * không có trong Make Me a Hanzi. `null` cho chữ cnchar không biết —
             * phồn thể và ký tự không phải Hán. Mảng RỖNG sẽ đọc ra là "0 nét".
             */
            $table->jsonb('stroke_names')->nullable();

            // Hình học nét cho `hanzi-writer`. ~4 KB mỗi chữ, nên nó đi endpoint
            // RIÊNG chứ không kèm chi tiết từ — xem `plan.md`, Validation Q1.
            $table->jsonb('strokes')->nullable();
            $table->jsonb('medians')->nullable();

            $table->timestampsTz();

            /*
             * KHÔNG index nào ngoài unique trên `char`. Mọi truy cập là tra chính
             * xác một ký tự hoặc một `whereIn` ngắn theo chữ của một từ.
             */
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_characters');
    }
};
