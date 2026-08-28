<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Nghĩa tiếng Việt do AI dọn lại, dùng để KHỚP. CVDICT giữ nguyên để HIỂN THỊ.
     *
     * Vấn đề đo được trên 115.040 mục có nghĩa Việt:
     *
     *   lẫn chữ Hán trong nghĩa                14.113
     *   lẫn tham chiếu pinyin kiểu `[nin2]`     4.092
     *   nghĩa ĐẦU có ngoặc chú thích           31.467
     *   ngoặc nằm ở ĐẦU nghĩa                   7.352
     *
     * Nhưng nhiễu ký tự không phải phần tệ nhất — THỨ TỰ nghĩa mới là:
     *
     *   的  → "xe taxi; xe cab (viết tắt của 的士[di1 shi4])"
     *   你  → "bạn (ngôi thứ hai thông dụng, khác với kính trọng 您[nin2])"
     *   吗  → "dùng trong 嗎啡|吗啡[ma3 fei1]"
     *
     * 的 là chữ phổ biến nhất tiếng Trung và nghĩa ĐẦU của nó là "xe taxi". Mọi
     * bậc `precision` của nhánh nghĩa Việt đều tính trên nghĩa đầu, nên thứ tự
     * sai làm hỏng xếp hạng chứ không chỉ làm bẩn màn hình.
     *
     * ## Vì sao cột THƯỜNG chứ không GENERATED
     *
     * `ADD COLUMN ... GENERATED ... STORED` lấy ACCESS EXCLUSIVE và viết lại
     * toàn bộ heap 92 MB CÙNG 17 index — migration cột gloss trước đã đo 4 giây
     * ở local và cảnh báo rằng đó là cận dưới.
     *
     * Cột nullable KHÔNG có default thì Postgres chỉ ghi metadata: tức thì, không
     * khóa gì đáng kể. Đổi lại, `dictionary:optimize-glosses` phải tự tính
     * `to_tsvector` lúc ghi. Đó là cái giá đúng để trả: một lần ghi mỗi từ, so
     * với một lần khóa bảng cho toàn bộ người dùng.
     */
    public function up(): void
    {
        Schema::table('dictionary_words', function (Blueprint $table): void {
            $table->jsonb('definitions_vi_ai')->nullable();
            $table->text('definitions_vi_ai_first')->nullable();
            $table->text('definitions_vi_ai_first_plain')->nullable();
            $table->string('vi_ai_model', 64)->nullable();
            $table->smallInteger('vi_ai_version')->nullable();
        });

        /*
         * `tsvector` không có trong Blueprint của Laravel, và cố tình KHÔNG
         * generated — xem ghi chú trên. Command ghi bằng `to_tsvector('simple', …)`
         * và `f_unaccent`, đúng hai biểu thức mà cột generated của CVDICT dùng,
         * để hai đường cho ra cùng một hình dạng token.
         */
        DB::statement('ALTER TABLE dictionary_words ADD COLUMN search_vi_ai_tsv tsvector');
        DB::statement('ALTER TABLE dictionary_words ADD COLUMN search_vi_ai_plain_tsv tsvector');

        DB::statement('CREATE INDEX dictionary_words_search_vi_ai_tsv ON dictionary_words USING gin (search_vi_ai_tsv)');
        DB::statement('CREATE INDEX dictionary_words_search_vi_ai_plain_tsv ON dictionary_words USING gin (search_vi_ai_plain_tsv)');

        // Quét phần chưa xử lý: `where vi_ai_version is null or vi_ai_version < N`.
        DB::statement('CREATE INDEX dictionary_words_vi_ai_version ON dictionary_words (vi_ai_version)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS dictionary_words_search_vi_ai_tsv');
        DB::statement('DROP INDEX IF EXISTS dictionary_words_search_vi_ai_plain_tsv');
        DB::statement('DROP INDEX IF EXISTS dictionary_words_vi_ai_version');
        DB::statement('ALTER TABLE dictionary_words DROP COLUMN IF EXISTS search_vi_ai_tsv');
        DB::statement('ALTER TABLE dictionary_words DROP COLUMN IF EXISTS search_vi_ai_plain_tsv');

        Schema::table('dictionary_words', function (Blueprint $table): void {
            $table->dropColumn([
                'definitions_vi_ai', 'definitions_vi_ai_first',
                'definitions_vi_ai_first_plain', 'vi_ai_model', 'vi_ai_version',
            ]);
        });
    }
};
