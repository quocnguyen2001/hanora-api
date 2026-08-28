<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Câu dịch cho truy vấn dạng CÂU.
     *
     * Lỗi mà cột này sửa: `bạn có nhớ tôi không?` trả về 你 / 记得 / 我 / 想念 —
     * các từ rời, không phải câu trả lời. AI thực ra ĐÃ trả về `你还记得我吗`,
     * nhưng `SearchInterpreter::resolve()` vứt nó đi vì nó không có trong
     * `dictionary_words`.
     *
     * Luật tra ngược corpus đó đúng cho từ ghép và SAI cho câu: một câu không
     * bao giờ là mục từ điển, nên luật chống bịa vô tình giết đúng câu trả lời
     * hữu ích nhất.
     *
     * Cột riêng chứ không nhét vào `word_ids`: câu dịch không có `id`, không lưu
     * được vào sổ từ vựng, và không phải một mục từ. Trộn hai loại vào một mảng
     * là buộc mọi consumer phải phân biệt chúng bằng cách đoán.
     */
    public function up(): void
    {
        Schema::table('search_query_interpretations', function (Blueprint $table): void {
            $table->jsonb('translation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('search_query_interpretations', function (Blueprint $table): void {
            $table->dropColumn('translation');
        });
    }
};
