<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        /*
         * PostgreSQL đòi biểu thức trong generated column và index phải
         * IMMUTABLE. `unaccent()` được khai báo STABLE — nó phân giải dictionary
         * qua `search_path`, nên `to_tsvector('simple', unaccent(col))` bị TỪ
         * CHỐI THẲNG ngay tại CREATE TABLE.
         *
         * Wrapper dưới đây chỉ định dictionary tường minh, nhờ vậy khai báo
         * IMMUTABLE là trung thực chứ không phải nói dối trình tối ưu.
         *
         * Ràng buộc kèm theo: dictionary `unaccent` không được đổi mà không
         * REINDEX. Và MỌI truy vấn ở P6 phải gọi đúng wrapper này — gọi
         * `unaccent()` trần sẽ không dùng được index.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text
              LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT AS
            $$ SELECT public.unaccent('public.unaccent', $1) $$
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS f_unaccent(text)');
    }
};
