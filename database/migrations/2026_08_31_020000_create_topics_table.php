<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Danh mục chủ đề học từ vựng.
     *
     * NGUỒN SỰ THẬT là hằng `TopicCatalog` trong code, KHÔNG phải bảng này.
     * Bảng tồn tại vì hai lý do hẹp: cấp `topic_id` cho khoá ngoại của
     * `topic_words`, và cho phép đếm `word_count` bằng một truy vấn thay vì
     * nạp cả danh mục vào PHP.
     *
     * Hệ quả bắt buộc: `TopicResource` đọc `name`/`emoji` từ `TopicCatalog`,
     * KHÔNG từ bảng. Đọc từ bảng thì đổi tên chủ đề trong code mà quên chạy
     * `topics:import` sẽ khiến app hiện tên cũ trong khi code nói tên mới —
     * không có test nào đỏ, không có log nào. `topics:import` assert hai bên
     * khớp để chặn đúng ca đó.
     */
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table): void {
            $table->id();

            /*
             * `slug` ĐÓNG BĂNG VĨNH VIỄN sau lần chạy đầu tiên.
             *
             * Nó nằm trong URL công khai `/topics/{slug}` và trong tên file
             * `database/data/topics/{slug}.json` đã commit vào git. Đổi slug là
             * đổi cả hai thứ đó cùng lúc, cộng thêm một lần chạy lại
             * `topics:generate`.
             */
            $table->string('slug', 32)->unique();

            // Bản chiếu của `TopicCatalog`, không phải nguồn. Xem ghi chú trên.
            $table->string('name', 64);
            $table->string('emoji', 8);
            $table->smallInteger('sort_order');

            $table->timestampsTz();

            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topics');
    }
};
