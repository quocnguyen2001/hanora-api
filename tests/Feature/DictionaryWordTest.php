<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);
    Artisan::call('han-viet:import', [
        '--unihan' => base_path('tests/Fixtures/unihan-sample.txt'),
        '--supplement' => base_path('tests/Fixtures/hanviet-supplement-sample.csv'),
    ]);

    $this->user = User::factory()->create();
});

function showWord(string $simplified, ?string $pinyin = null): TestResponse
{
    $query = DictionaryWord::where('simplified', $simplified);

    if ($pinyin !== null) {
        $query->where('pinyin_numbered', $pinyin);
    }

    $id = $query->value('id');

    return test()->actingAs(test()->user, 'sanctum')->getJson("/api/dictionary/words/{$id}");
}

describe('chi tiết từ', function (): void {
    it('trả đủ trường theo hợp đồng với P8', function (): void {
        $data = showWord('学习')->assertOk()->json('data');

        expect(array_keys($data))->toBe([
            'id', 'simplified', 'traditional', 'pinyin', 'han_viet',
            'definitions_en', 'definitions_vi', 'measure_words', 'hsk_level', 'characters', 'examples',
        ]);
    });

    it('trả 404 cho id không tồn tại', function (): void {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/dictionary/words/999999')
            ->assertNotFound();
    });

    it('KHÔNG chứa trường theo user', function (): void {
        // Red team C2: response này cache dài hạn ở cả HTTP lẫn service worker.
        $data = showWord('学习')->json('data');

        expect($data)->not->toHaveKey('saved')
            ->and($data)->not->toHaveKey('user_word_id');
    });

    it('giữ định nghĩa tiếng Anh ngay cả khi có âm Hán-Việt', function (): void {
        // R1: âm Hán-Việt KHÔNG BAO GIỜ thay thế định nghĩa tiếng Anh. Với từ
        // khẩu ngữ như 东西 (`đông tây` nhưng nghĩa là "thứ, đồ vật"), âm Hán-
        // Việt không phải nghĩa — bỏ tiếng Anh đi là dạy sai.
        $data = showWord('东西')->json('data');

        expect($data['han_viet'])->toBe('đông tây')
            ->and($data['definitions_en'])->not->toBeEmpty();
    });

    it('trả han_viet null chứ không phải chuỗi rỗng khi chưa ghép được', function (): void {
        $data = showWord('沙发')->json('data');

        expect($data['han_viet'])->toBeNull()
            ->and($data['definitions_en'])->not->toBeEmpty();
    });
});

describe('phân tích Hán tự — red team H11', function (): void {
    it('chọn âm đọc ĐÚNG NGỮ CẢNH cho chữ đa âm', function (): void {
        // Đây là ca chính: 银行 là `yínháng`, nên 行 phải là `háng` (hàng), KHÔNG
        // phải `xíng` (đi). Tra 行 mà không khớp âm là dạy sai đúng thứ người
        // học đang học.
        $characters = showWord('银行')->json('data.characters');

        expect($characters)->toHaveCount(2)
            ->and($characters[1]['char'])->toBe('行')
            ->and($characters[1]['pinyin'])->toBe('háng')
            ->and($characters[1]['han_viet'])->toBe('hàng');
    });

    it('kèm âm Hán-Việt của từng chữ', function (): void {
        $characters = showWord('学习')->json('data.characters');

        expect($characters[0])->toMatchArray(['char' => '学', 'pinyin' => 'xué', 'han_viet' => 'học'])
            ->and($characters[1])->toMatchArray(['char' => '习', 'pinyin' => 'xí', 'han_viet' => 'tập']);
    });

    it('bỏ hẳn mục khi không chữ nào khớp âm, không hiện âm sai', function (): void {
        // 沙发 — fixture không có 沙 và 发 dạng chữ đơn, nên breakdown rỗng chứ
        // không phải là một mục bịa.
        expect(showWord('沙发')->json('data.characters'))->toBe([]);
    });

    it('không phân tích chữ đơn', function (): void {
        // Chữ đơn thì chính nó là phân tích của nó.
        expect(showWord('学', 'xue2')->json('data.characters'))->toBe([]);
    });
});

describe('cache', function (): void {
    it('đặt Cache-Control public dài hạn', function (): void {
        // Dữ liệu từ điển tĩnh hoàn toàn sau V1, và response không theo user.
        showWord('学习')->assertOk()->assertHeader('Cache-Control', 'max-age=86400, public');
    });
});
