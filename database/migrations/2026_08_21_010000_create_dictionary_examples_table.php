<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictionary_examples', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('word_id')->constrained('dictionary_words')->cascadeOnDelete();

            $table->text('sentence_zh');
            $table->text('translation_en');

            /*
             * `contributor` và `license` KHÔNG phải cột thừa.
             *
             * Tatoeba là CC BY: nghĩa vụ là ghi công TÁC GIẢ của từng câu, và
             * Tatoeba xuất bản license theo từng câu chứ không phải một license
             * chung cho cả kho. Thiếu hai cột này thì ghi công đúng là bất khả
             * thi về mặt cấu trúc — không phải "làm sau cũng được".
             */
            $table->string('contributor', 64)->nullable();
            $table->string('license', 32)->default('CC BY 2.0 FR');

            $table->smallInteger('char_length');
            $table->smallInteger('quality_score');

            $table->string('source', 16)->default('tatoeba');
            $table->string('source_id', 32);

            $table->timestampsTz();

            // Lấy câu tốt nhất cho một từ là truy vấn duy nhất P6 chạy.
            $table->index(['word_id', 'quality_score']);
            $table->unique(['word_id', 'source', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_examples');
    }
};
