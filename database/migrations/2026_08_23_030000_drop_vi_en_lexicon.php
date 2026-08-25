<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Cầu nối hai chặng đã bị thay bằng nghĩa tiếng Việt trực tiếp.
     *
     * Bảng này phủ 54.213 mục qua gloss tiếng Anh; `definitions_vi` phủ 115.040
     * dòng không qua chặng trung gian nào. Giữ lại là giữ một đường sinh nhiễu
     * đã được thay thế, cộng một lệnh import nữa trong runbook production.
     *
     * `down()` dựng lại bảng RỖNG, và chỉ có thế. Nguồn VNEDICT đã bị gỡ khỏi
     * repo cùng commit này nên không còn đường nạp lại dữ liệu; rollback ở đây
     * là để schema khớp lại, KHÔNG phải để hồi sinh tính năng. Muốn quay lại cầu
     * nối thì revert cả commit.
     *
     * Dựng lại thay vì ném exception: một `down()` luôn ném làm cả BATCH không
     * rollback được, và biến một thao tác khẩn cấp bình thường thành bế tắc.
     */
    public function up(): void
    {
        Schema::dropIfExists('vi_en_lexicon');
    }

    public function down(): void
    {
        Schema::create('vi_en_lexicon', function (Blueprint $table): void {
            $table->id();
            $table->string('term', 128)->unique();
            $table->string('term_plain', 128)->index();
            $table->jsonb('senses');
            $table->timestampsTz();
        });
    }
};
