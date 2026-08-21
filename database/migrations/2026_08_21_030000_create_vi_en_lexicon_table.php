<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Bảng cầu nối Việt → Anh, KHÔNG phải nội dung hiển thị.
     *
     * App vẫn chú giải bằng âm Hán-Việt và định nghĩa tiếng Anh (D1 giữ nguyên).
     * Bảng này chỉ dịch chuỗi người dùng gõ sang tập từ khóa tiếng Anh để khớp
     * với `dictionary_words.search_tsv` — không dòng nào của nó tới được UI.
     */
    public function up(): void
    {
        Schema::create('vi_en_lexicon', function (Blueprint $table): void {
            $table->id();

            /*
             * varchar(64), không phải 128.
             *
             * Đo trên VNEDICT thật: term dài nhất 60 ký tự, không mục nào vượt.
             * Một batch upsert chạm phải chuỗi quá dài sẽ hỏng cả lô, nên con số
             * này là số đo chứ không phải phỏng đoán rộng rãi cho chắc.
             */
            $table->string('term', 64);
            $table->string('term_plain', 64);

            /*
             * Nghĩa lưu THÔ — chỉ tách và bỏ chú thích trong ngoặc.
             *
             * Mọi ngưỡng lọc nhiễu (bỏ hư từ tiếng Anh, giới hạn độ dài, trần số
             * từ khóa) nằm ở `VietnameseQueryBridge`, chạy lúc truy vấn. Đặt
             * chúng ở đây thì mỗi lần chỉnh một hằng số là một lần reimport 54k
             * dòng trên production — và bảng so sánh kiến trúc của plan sẽ nói
             * dối về chính nó.
             */
            $table->jsonb('senses');

            $table->timestampsTz();

            /*
             * `term` là khóa tự nhiên, KHÔNG phải `term_plain`.
             *
             * `ma` / `mà` / `má` là ba mục khác nhau với ba nghĩa khác nhau. Gộp
             * chúng theo dạng bỏ dấu sẽ trộn "ghost / but / cheek" vào một chỗ và
             * không có đường nào tách lại. Truy vấn CÓ dấu tra `term` và nhận
             * đúng nghĩa; truy vấn KHÔNG dấu tra `term_plain`, chấp nhận nhiều
             * dòng, và gộp ở tầng service với thứ tự xác định.
             */
            $table->unique('term');
            $table->index('term_plain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vi_en_lexicon');
    }
};
