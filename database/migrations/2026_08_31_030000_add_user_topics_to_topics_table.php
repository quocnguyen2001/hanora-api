<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Chủ đề do NGƯỜI DÙNG tự tạo, sinh bằng AI theo yêu cầu.
     *
     * `user_id` nullable là toàn bộ cơ chế phân quyền của tính năng:
     * `NULL` = một trong 16 chủ đề gốc của `TopicCatalog`, ai cũng thấy;
     * có giá trị = chủ đề riêng, CHỈ người tạo thấy.
     *
     * Phạm vi cá nhân là thứ thay cho bước rà bằng mắt mà D3/D7 dựa vào: nội
     * dung chưa ai duyệt không bao giờ được dạy cho người khác.
     */
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table): void {
            /*
             * CASCADE, khác `user_skipped_words` (restrict).
             *
             * Chủ đề tự tạo là nội dung PHÁI SINH — dựng lại được bằng cách gõ
             * lại tên. Còn một dòng "đã biết rồi" là quyết định con người không
             * tái tạo được, nên nó mới cần restrict.
             */
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();

            // `ready` cho mọi dòng đang có: 16 chủ đề gốc nạp từ JSON đã xong.
            $table->string('status', 16)->default('ready')->after('sort_order');

            /*
             * Chuỗi đi vào prompt.
             *
             * Chủ đề gốc lấy từ `TopicCatalog` (tên NGẮN đã đo, khác tên hiển
             * thị ghép). Chủ đề tự tạo thì chính tên người dùng gõ là prompt.
             */
            $table->string('prompt_term', 64)->nullable()->after('name');

            $table->string('failed_reason', 64)->nullable()->after('status');

            $table->index(['user_id', 'sort_order']);
        });

        /*
         * HAI unique index, không phải một.
         *
         * `unique(user_id, slug)` một mình KHÔNG đủ: Postgres coi mọi `NULL` là
         * khác nhau, nên hai chủ đề GỐC trùng slug vẫn lọt qua. Tách làm hai
         * index có điều kiện để mỗi nhóm được khoá đúng cách.
         */
        /*
         * DROP CONSTRAINT trước, không phải DROP INDEX.
         *
         * `$table->unique('slug')` ở migration gốc tạo một CONSTRAINT, và
         * Postgres từ chối `DROP INDEX` trên index đang chống lưng cho một
         * constraint: "cannot drop index ... because constraint ... requires it".
         */
        DB::statement('ALTER TABLE topics DROP CONSTRAINT IF EXISTS topics_slug_unique');
        DB::statement('DROP INDEX IF EXISTS topics_slug_unique');

        DB::statement(
            'CREATE UNIQUE INDEX topics_global_slug_unique ON topics (slug) WHERE user_id IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX topics_user_slug_unique ON topics (user_id, slug) WHERE user_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS topics_global_slug_unique');
        DB::statement('DROP INDEX IF EXISTS topics_user_slug_unique');

        Schema::table('topics', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['status', 'prompt_term', 'failed_reason']);
            $table->unique('slug');
        });
    }
};
