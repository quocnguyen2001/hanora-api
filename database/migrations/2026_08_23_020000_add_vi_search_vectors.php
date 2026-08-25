<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /*
     * HAI tsvector cho nghĩa tiếng Việt, không phải một.
     *
     * Nguyên mẫu đầu dùng `f_unaccent` cả hai vế và cho ra rác đo được:
     * `chó` → 你/他/吗 (vì `cho` có trong hàng chục nghìn định nghĩa),
     * `bàn` → 你/我们 (`ban`), `táo` → 么/远/秀 (`tao`).
     *
     * Bỏ dấu phá tiếng Việt nặng hơn phá pinyin rất nhiều — tiếng Việt đầy cặp
     * tối thiểu chỉ khác nhau ở thanh điệu, còn pinyin bỏ dấu vẫn giữ được âm
     * tiết. Nên vector CÓ DẤU là đường CHÍNH, vector không dấu chỉ là dự phòng
     * cho người gõ không dấu (`may tinh`) và xếp SAU.
     *
     * Đây đúng là cặp `term` / `term_plain` của bảng lexicon, lặp lại ở tầng
     * định nghĩa. Ghi lại ở đây để không ai gộp chúng thành một.
     *
     * `f_unaccent` chứ không phải `unaccent`: generated column đòi hàm
     * IMMUTABLE, và đó là lý do wrapper tồn tại.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE dictionary_words
            ADD COLUMN search_vi_tsv tsvector
            GENERATED ALWAYS AS (
                to_tsvector('simple', coalesce(definitions_vi_text, ''))
            ) STORED,
            ADD COLUMN search_vi_plain_tsv tsvector
            GENERATED ALWAYS AS (
                to_tsvector('simple', f_unaccent(coalesce(definitions_vi_text, '')))
            ) STORED
        SQL);

        DB::statement('CREATE INDEX dictionary_words_search_vi_tsv ON dictionary_words USING gin (search_vi_tsv)');
        DB::statement('CREATE INDEX dictionary_words_search_vi_plain_tsv ON dictionary_words USING gin (search_vi_plain_tsv)');

        /*
         * Nghĩa ĐẦU, đã thường hóa — hai dạng, dựng sẵn.
         *
         * Xếp hạng phân biệt "nghĩa đầu đúng bằng truy vấn" với "nghĩa đầu có
         * chứa truy vấn", nên hai biểu thức này chạy trên MỌI dòng mà GIN trả
         * về. Đo trên ca fan-out cao nhất (`nguoi`, 5.909 dòng):
         *
         *   tính tại chỗ  `f_unaccent(lower(definitions_vi->>0))`  +36 ms
         *   cột dựng sẵn                                            +1 ms
         *
         * 36ms đó đủ để đẩy cả truy vấn qua ngưỡng 150ms của P6. Đây là bước (1)
         * "thêm/sửa index" trong thứ tự leo thang mà benchmark quy định, và nó
         * đủ — không cần tới bước (2) hay (3).
         *
         * KHÔNG đánh index: hai cột này chỉ xuất hiện trong danh sách SELECT để
         * tính bậc, không bao giờ nằm trong `WHERE`. Index ở đây là dung lượng
         * và chi phí ghi không ai đọc.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE dictionary_words
            ADD COLUMN definitions_vi_first text
            GENERATED ALWAYS AS (lower(coalesce(definitions_vi->>0, ''))) STORED,
            ADD COLUMN definitions_vi_first_plain text
            GENERATED ALWAYS AS (f_unaccent(lower(coalesce(definitions_vi->>0, '')))) STORED
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS dictionary_words_search_vi_plain_tsv');
        DB::statement('DROP INDEX IF EXISTS dictionary_words_search_vi_tsv');
        DB::statement(
            'ALTER TABLE dictionary_words '
            .'DROP COLUMN IF EXISTS definitions_vi_first_plain, '
            .'DROP COLUMN IF EXISTS definitions_vi_first, '
            .'DROP COLUMN IF EXISTS search_vi_plain_tsv, '
            .'DROP COLUMN IF EXISTS search_vi_tsv'
        );
    }
};
