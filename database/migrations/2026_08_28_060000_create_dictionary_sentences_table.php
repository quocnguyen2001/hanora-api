<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Phân tích một CÂU tiếng Trung: tách từ, nghĩa đen, ghi chú ngữ pháp.
     *
     * Khoá là chính chuỗi Hán đã chuẩn hoá, KHÔNG phải một id sinh ra rồi trả
     * về. Lý do nằm ở luồng người dùng: họ bấm vào thẻ dịch và phải sang trang
     * ngay. Nếu khoá là id thì FE buộc phải gọi API trước rồi mới điều hướng
     * được — một vòng chờ 4 giây trên màn hình cũ, trước khi có gì để hiện.
     *
     * Cùng một câu tiếng Trung có thể tới từ nhiều truy vấn tiếng Việt khác
     * nhau ("bạn có nhớ tôi không" và "cậu còn nhớ tớ chứ"), nên khoá theo câu
     * Hán gộp chúng lại thành một lần phân tích.
     */
    public function up(): void
    {
        Schema::create('dictionary_sentences', function (Blueprint $table): void {
            $table->id();

            /*
             * 200 ký tự: dài hơn thế không phải câu người ta tra, và để nguyên
             * thì một đoạn dán nhầm sẽ vỡ trần 2704 byte của index btree.
             */
            $table->string('zh_normalized', 200)->unique();

            $table->jsonb('payload')->nullable();
            $table->string('model', 64)->nullable();
            $table->smallInteger('prompt_version')->default(1);

            // Câu mà model luôn trả rác sẽ bị phân tích lại mỗi lần có người mở
            // trang. Không có bộ đếm này thì không có gì dừng vòng đó lại.
            $table->smallInteger('attempts')->default(0);

            $table->timestampTz('generated_at')->nullable();
            $table->timestampsTz();

            $table->index('prompt_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_sentences');
    }
};
