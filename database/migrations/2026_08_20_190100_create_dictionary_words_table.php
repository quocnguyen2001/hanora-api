<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * TOÀN BỘ schema của `dictionary_words` thuộc về P4.
     *
     * Các cột `han_viet*` để trống ở đây và do P5 điền dữ liệu — nhưng chúng
     * được tạo ngay từ migration này có chủ đích: thêm cột vào bảng 120k dòng ở
     * phase sau là một lần ALTER TABLE khóa bảng mà không ai cần.
     */
    public function up(): void
    {
        Schema::create('dictionary_words', function (Blueprint $table): void {
            $table->id();

            $table->string('simplified', 64);
            $table->string('traditional', 64);

            $table->string('pinyin', 128);            // xuéxí
            $table->string('pinyin_numbered', 128);   // xue2 xi2 — dạng gốc CC-CEDICT
            $table->string('pinyin_plain', 128);      // xuexi

            $table->jsonb('definitions_en');          // mảng sense
            $table->text('definitions_en_text');      // bản phẳng, phục vụ full-text

            // P5 điền. `pending` là trạng thái khởi tạo của mọi bản ghi mới.
            $table->string('han_viet', 128)->nullable();
            $table->string('han_viet_plain', 128)->nullable();
            $table->string('han_viet_status', 16)->default('pending');

            $table->smallInteger('hsk_level')->nullable();
            $table->integer('frequency_rank')->nullable();
            $table->boolean('is_priority')->default(false);

            $table->smallInteger('char_count');
            $table->boolean('is_single_char');

            $table->timestampsTz();

            /*
             * CC-CEDICT có nhiều mục cùng chữ khác âm (行 hành/hàng), nên chữ
             * giản thể một mình KHÔNG phải khóa. Cặp (giản thể, pinyin số) mới
             * là khóa tự nhiên, và nó là thứ khiến import chạy lại được.
             */
            $table->unique(['simplified', 'pinyin_numbered'], 'dictionary_words_natural_key');
        });

        /*
         * `search_tsv` gộp âm Hán-Việt và định nghĩa tiếng Anh vào một vector.
         *
         * Dùng `f_unaccent` chứ không phải `unaccent`: xem migration wrapper.
         * Dùng cấu hình `simple` chứ không phải `english` vì cột này chứa cả
         * tiếng Việt đã bỏ dấu — stemmer tiếng Anh sẽ cắt sai chúng.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE dictionary_words
            ADD COLUMN search_tsv tsvector
            GENERATED ALWAYS AS (
                to_tsvector('simple',
                    f_unaccent(coalesce(han_viet, '') || ' ' || definitions_en_text)
                )
            ) STORED
        SQL);

        Schema::table('dictionary_words', function (Blueprint $table): void {
            $table->index('simplified');
            $table->index('traditional');
            $table->index('pinyin_plain');
            $table->index('han_viet_plain');
            $table->index('frequency_rank');
            $table->index('hsk_level');
            $table->index('is_priority');
            $table->index('han_viet_status');
        });

        /*
         * `varchar_pattern_ops` cho prefix match (`LIKE 'xuex%'`). Index btree
         * mặc định dùng collation của database nên KHÔNG phục vụ được LIKE.
         */
        DB::statement('CREATE INDEX dictionary_words_pinyin_plain_prefix ON dictionary_words (pinyin_plain varchar_pattern_ops)');
        DB::statement('CREATE INDEX dictionary_words_han_viet_plain_prefix ON dictionary_words (han_viet_plain varchar_pattern_ops)');

        // Trigram cho fuzzy: gõ sai một hai ký tự vẫn ra kết quả.
        DB::statement('CREATE INDEX dictionary_words_pinyin_plain_trgm ON dictionary_words USING gin (pinyin_plain gin_trgm_ops)');
        DB::statement('CREATE INDEX dictionary_words_han_viet_plain_trgm ON dictionary_words USING gin (han_viet_plain gin_trgm_ops)');

        DB::statement('CREATE INDEX dictionary_words_search_tsv ON dictionary_words USING gin (search_tsv)');
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_words');
    }
};
