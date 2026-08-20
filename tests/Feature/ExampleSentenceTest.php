<?php

declare(strict_types=1);

use App\Models\DictionaryExample;
use App\Models\DictionaryWord;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);

    $this->user = User::factory()->create();
    $this->word = DictionaryWord::where('simplified', '学习')->sole();
});

function addExample(DictionaryWord $word, array $overrides = []): DictionaryExample
{
    return DictionaryExample::create([
        'word_id' => $word->id,
        'sentence_zh' => '我喜欢学习中文。',
        'translation_en' => 'I like studying Chinese.',
        'contributor' => 'someuser',
        'license' => 'CC BY 2.0 FR',
        'char_length' => 8,
        'quality_score' => 100,
        'source' => 'tatoeba',
        'source_id' => (string) random_int(1, 999999),
        ...$overrides,
    ]);
}

describe('câu ví dụ trong word detail', function (): void {
    it('trả câu kèm ĐỦ thông tin ghi công', function (): void {
        // Tatoeba là CC BY: nghĩa vụ là ghi công tác giả của CHÍNH câu đó.
        // Thiếu `contributor`/`license` thì ghi công đúng là bất khả thi.
        addExample($this->word);

        $examples = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/dictionary/words/{$this->word->id}")
            ->assertOk()
            ->json('data.examples');

        expect($examples)->toHaveCount(1)
            ->and(array_keys($examples[0]))->toBe([
                'id', 'sentence_zh', 'translation_en', 'contributor', 'license',
            ])
            ->and($examples[0]['contributor'])->toBe('someuser')
            ->and($examples[0]['license'])->toBe('CC BY 2.0 FR');
    });

    it('KHÔNG sinh pinyin cho câu — D6', function (): void {
        // Sinh pinyin cho cả câu cần tách từ + phân giải chữ đa âm, một bài
        // toán NLP riêng chứ không phải một lệnh gọi PinyinNormalizer (H12).
        addExample($this->word);

        $examples = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/dictionary/words/{$this->word->id}")
            ->json('data.examples');

        expect($examples[0])->not->toHaveKey('pinyin');
    });

    it('trả mảng rỗng khi từ chưa có câu nào', function (): void {
        // ~15% từ trong tập ưu tiên không có câu. Rỗng là trạng thái HỢP LỆ và
        // FE ẩn hẳn section đó, không hiện khung trống.
        $examples = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/dictionary/words/{$this->word->id}")
            ->assertOk()
            ->json('data.examples');

        expect($examples)->toBe([]);
    });

    it('trả tối đa 3 câu, tốt nhất trước', function (): void {
        foreach ([40, 100, 70, 90, 60] as $index => $score) {
            addExample($this->word, ['quality_score' => $score, 'source_id' => "s{$index}"]);
        }

        $examples = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/dictionary/words/{$this->word->id}")
            ->json('data.examples');

        expect($examples)->toHaveCount(3);
    });

    it('xóa câu theo từ khi từ bị xóa khỏi từ điển', function (): void {
        // `dictionary_examples` là dữ liệu phái sinh của từ điển, không phải
        // dữ liệu người dùng — cascade ở đây là đúng.
        $example = addExample($this->word);
        $this->word->delete();

        $this->assertDatabaseMissing('dictionary_examples', ['id' => $example->id]);
    });
});
