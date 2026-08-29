<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Ảnh minh hoạ của một mục từ, resolve từ Pixabay.
     *
     * `CREATE TABLE` mới nên không cần `lock_timeout` — không khóa gì đang nóng.
     */
    public function up(): void
    {
        Schema::create('dictionary_word_illustrations', function (Blueprint $table): void {
            $table->id();

            /*
             * `unique` trên `word_id`, KHÔNG phải `(word_id, gate_version)`.
             *
             * Một từ chỉ cần MỘT ảnh đang dùng. `gate_version` tồn tại để TÌM
             * bản ghi sinh bởi cổng cũ (`where gate_version < N`) rồi ghi đè
             * chính dòng đó — giữ nhiều thế hệ song song là chỗ chứa không ai
             * đọc, trên một bảng có thể lên tới 123.646 dòng.
             */
            $table->foreignId('word_id')->unique()
                ->constrained('dictionary_words')->cascadeOnDelete();

            /*
             * `none` KHÔNG phải một biến thể của `failed`.
             *
             * "Từ này đúng ra không nên có ảnh" (hư từ, từ trừu tượng) là kết
             * quả THÀNH CÔNG và vĩnh viễn. Gộp nó vào `failed` thì mỗi lần có
             * người mở `的` lại tốn thêm hai request Pixabay cho một câu trả
             * lời đã biết từ trước.
             */
            $table->string('status', 16)->default('pending');

            /*
             * URL trên `cdn.pixabay.com`, bản `_640`.
             *
             * KHÔNG lưu `webformatURL` — docs Pixabay ghi rõ "URL valid for 24
             * hours", nên cache nó là cache một thứ tự huỷ sau một ngày.
             * `preview_url` (bản `_150`, có trong docs) là đường lui khi bản
             * `_640` suy ra được lại hỏng.
             */
            $table->text('image_url')->nullable();
            $table->text('preview_url')->nullable();

            /*
             * `page_url`, `author`, `author_url` là NGHĨA VỤ theo ToS Pixabay
             * ("show your users where the images are from"), không phải siêu dữ
             * liệu cho vui.
             */
            $table->text('page_url')->nullable();
            $table->string('author', 120)->nullable();
            $table->text('author_url')->nullable();

            $table->bigInteger('source_id')->nullable();

            // Của bản `_640`, để FE đặt `width`/`height` và không bị layout shift.
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();

            /*
             * Truy vấn đã khớp. Đây là thứ DUY NHẤT trả lời được "vì sao từ này
             * ra đúng ảnh đó" khi cần chỉnh cổng — không có nó thì mọi lần
             * chỉnh ngưỡng đều là đoán mò.
             */
            $table->string('matched_query', 120)->nullable();

            $table->smallInteger('gate_version')->default(1);

            /*
             * `attempts` và `failed_reason` KHÔNG phải cột thừa: không có chúng,
             * một từ mà Pixabay luôn trả lỗi sẽ bị resolve lại vô hạn — mỗi lần
             * ai mở màn chi tiết là hai request mới cho một kết quả đã biết hỏng.
             */
            $table->smallInteger('attempts')->default(0);
            $table->string('failed_reason', 64)->nullable();

            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
            // Quét bản ghi cũ khi cổng đổi: `where status = none and gate_version < N`.
            $table->index(['status', 'gate_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_word_illustrations');
    }
};
