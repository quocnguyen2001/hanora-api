<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Nội dung làm giàu do Gemini sinh: nghĩa theo từ loại, ví dụ song ngữ,
     * bộ thủ và số nét, từ ghép liên quan, thành ngữ.
     *
     * `CREATE TABLE` mới nên KHÔNG cần `lock_timeout` như migration cột gloss:
     * ở đó là `ALTER TABLE ... GENERATED` viết lại toàn bộ heap 92 MB của
     * `dictionary_words` và dựng lại 17 index; ở đây không khóa gì đang nóng.
     */
    public function up(): void
    {
        Schema::create('dictionary_word_enrichments', function (Blueprint $table): void {
            $table->id();

            /*
             * `unique` trên `word_id`, KHÔNG phải `(word_id, prompt_version)`.
             *
             * Một từ chỉ cần MỘT bản làm giàu đang dùng. `prompt_version` tồn
             * tại để TÌM bản ghi đã cũ khi prompt đổi (`where prompt_version < N`)
             * rồi ghi đè chính dòng đó. Giữ nhiều thế hệ song song là chỗ chứa
             * không ai đọc, trên một bảng có thể lên tới 123.646 dòng.
             */
            $table->foreignId('word_id')->unique()
                ->constrained('dictionary_words')->cascadeOnDelete();

            $table->string('status', 16)->default('pending');
            $table->jsonb('payload')->nullable();
            $table->string('model', 64)->nullable();
            $table->smallInteger('prompt_version')->default(1);

            /*
             * `attempts` và `failed_reason` KHÔNG phải cột thừa.
             *
             * Không có chúng, một từ mà Gemini luôn trả rác sẽ bị dispatch lại
             * vô hạn — mỗi lần có người mở màn chi tiết là một lời gọi mới cho
             * một câu trả lời đã biết là hỏng.
             */
            $table->smallInteger('attempts')->default(0);
            $table->string('failed_reason', 64)->nullable();

            $table->timestampTz('generated_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
            // Quét bản ghi cũ khi prompt đổi: `where status=ready and prompt_version < N`.
            $table->index(['status', 'prompt_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_word_enrichments');
    }
};
