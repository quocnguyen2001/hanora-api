<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DictionaryWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sinh mục từ điển giả cho test cần NHIỀU từ (phân trang, phiên ôn tập).
 *
 * Test nào kiểm nội dung thật — chuẩn hóa pinyin, ghép Hán-Việt — phải dùng
 * fixture CC-CEDICT chứ không dùng factory này: dữ liệu ở đây không có ý nghĩa
 * ngôn ngữ.
 *
 * @extends Factory<DictionaryWord>
 */
final class DictionaryWordFactory extends Factory
{
    protected $model = DictionaryWord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $index = $this->faker->unique()->numberBetween(1, 100_000);
        $syllable = 'ci'.$index;

        return [
            // Dùng khối CJK Extension B để không đụng chữ thật trong fixture.
            'simplified' => mb_chr(0x20000 + $index, 'UTF-8'),
            'traditional' => mb_chr(0x20000 + $index, 'UTF-8'),
            'pinyin' => $syllable,
            'pinyin_numbered' => $syllable.'1',
            'pinyin_plain' => $syllable,
            'definitions_en' => ['placeholder definition '.$index],
            'definitions_en_text' => 'placeholder definition '.$index,
            'han_viet' => 'âm '.$index,
            'han_viet_plain' => 'am '.$index,
            'han_viet_status' => DictionaryWord::STATUS_OK,
            'char_count' => 1,
            'is_single_char' => true,
        ];
    }
}
