<?php

declare(strict_types=1);

use App\Models\DictionaryCharacter;
use App\Models\DictionaryWord;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);

    Artisan::call('characters:import', [
        '--dictionary' => base_path('tests/Fixtures/makemeahanzi-dictionary-sample.txt'),
        '--graphics' => base_path('tests/Fixtures/makemeahanzi-graphics-sample.txt'),
        '--stroke-names' => base_path('tests/Fixtures/stroke-names-sample.json'),
    ]);

    // `forWord` cache 30 ngày; import trong `beforeEach` không tự dọn nó, nên
    // test thứ hai sẽ đọc bản cache của test thứ nhất.
    Cache::flush();

    $this->user = User::factory()->create();
});

function strokesApi(string $char): TestResponse
{
    return test()->actingAs(test()->user, 'sanctum')
        ->getJson('/api/dictionary/characters/'.rawurlencode($char).'/strokes');
}

function characters(string $simplified, string $pinyinNumbered): array
{
    $word = DictionaryWord::query()
        ->where('simplified', $simplified)
        ->where('pinyin_numbered', $pinyinNumbered)
        ->firstOrFail();

    return test()->actingAs(test()->user, 'sanctum')
        ->getJson("/api/dictionary/words/{$word->id}")
        ->json('data.characters');
}

describe('import', function (): void {
    it('nhập đủ sáu thuộc tính', function (): void {
        $row = DictionaryCharacter::query()->where('char', '学')->firstOrFail();

        expect($row->radical)->toBe('子')
            ->and($row->stroke_count)->toBe(8)
            ->and($row->decomposition)->toBe('⿱⿱⺍冖子')
            ->and($row->etymology_type)->toBe('ideographic')
            ->and($row->stroke_names)->toHaveCount(8);
    });

    it('GIỮ NGUYÊN IDS có ？ nhúng giữa', function (): void {
        /*
         * `习` → `⿹？冫`. Khác hẳn ca "cả chuỗi là ？": ở đây IDS vẫn nói được
         * cấu trúc chữ (bao từ trên-phải, có 冫), chỉ thiếu tên một thành phần.
         *
         * Đo được: 9.125 chữ sạch, 383 có `？` nhúng, 66 có `？` là cả chuỗi.
         * Vứt 383 chữ đó đi để tránh một ký tự lạ là mất thông tin thật.
         */
        expect(DictionaryCharacter::query()->where('char', '习')->value('decomposition'))
            ->toBe('⿹？冫');
    });

    it('để stroke_names null cho chữ không có trong bảng nét bút', function (): void {
        // Phần lớn là chữ PHỒN THỂ — cnchar chỉ phủ giản thể. `null` chứ không
        // mảng rỗng: mảng rỗng đọc ra là "chữ này có 0 nét".
        expect(DictionaryCharacter::query()->where('char', '銀')->value('stroke_names'))->toBeNull();
    });

    it('gắn âm Hán-Việt cho bộ thủ từ chính dictionary_words', function (): void {
        /*
         * Không dùng `HanVietReadingTable` — `dictionary_words` LÀ nơi âm
         * Hán-Việt đã qua rà tay của P5 nằm, và tra chỗ khác là tự tạo nguồn
         * thứ hai để hai bên lệch nhau.
         *
         * Fixture không chạy `hanviet:import`, nên phải tự gieo âm rồi chạy lại
         * lượt import để kiểm chính cơ chế đó.
         */
        DictionaryWord::query()->where('simplified', '行')->update(['han_viet' => 'hành']);

        Artisan::call('characters:import', [
            '--dictionary' => base_path('tests/Fixtures/makemeahanzi-dictionary-sample.txt'),
            '--graphics' => base_path('tests/Fixtures/makemeahanzi-graphics-sample.txt'),
        ]);

        // Bộ thủ của 行 chính là 行.
        expect(DictionaryCharacter::query()->where('char', '行')->value('radical_han_viet'))
            ->toBe('hành');
    });

    it('để âm bộ null khi bộ thủ không có trong corpus', function (): void {
        // Đo trên dữ liệu thật: 244/295 bộ tra ra âm. 51 bộ còn lại để `null`,
        // và FE hiện bộ thủ mà không có âm — thà thiếu còn hơn đoán.
        expect(DictionaryCharacter::query()->where('char', '学')->value('radical_han_viet'))
            ->toBeNull();
    });

    it('chạy lại không đổi số dòng', function (): void {
        $before = DictionaryCharacter::count();

        Artisan::call('characters:import', [
            '--dictionary' => base_path('tests/Fixtures/makemeahanzi-dictionary-sample.txt'),
            '--graphics' => base_path('tests/Fixtures/makemeahanzi-graphics-sample.txt'),
            '--stroke-names' => base_path('tests/Fixtures/stroke-names-sample.json'),
        ]);

        expect(DictionaryCharacter::count())->toBe($before);
    });
});

describe('metadata đi kèm chi tiết từ', function (): void {
    it('trả đủ thuộc tính cho từng chữ mà KHÔNG cần request thêm', function (): void {
        // Cả sáu thuộc tính nằm trong `/words/{id}`, không phải một endpoint
        // riêng cho mỗi chữ — xem plan, Validation Q1.
        $chars = characters('学习', 'xue2 xi2');

        expect($chars)->toHaveCount(2);
        expect($chars[0]['char'])->toBe('学')
            ->and($chars[0]['radical'])->toBe('子')
            ->and($chars[0]['stroke_count'])->toBe(8)
            ->and($chars[0]['etymology_type'])->toBe('ideographic');
    });

    it('trả đủ khoá kể cả khi chữ không có trong bảng', function (): void {
        // Hình dạng response phải giống nhau cho mọi chữ; nếu không FE phải
        // kiểm sự tồn tại của từng trường thay vì chỉ kiểm `null`.
        DictionaryCharacter::query()->delete();

        $chars = characters('学习', 'xue2 xi2');

        expect($chars[0])->toHaveKeys([
            'char', 'pinyin', 'han_viet', 'radical', 'radical_han_viet',
            'stroke_count', 'decomposition', 'etymology_type', 'stroke_names',
        ]);
        expect($chars[0]['radical'])->toBeNull()
            ->and($chars[0]['pinyin'])->not->toBeNull();
    });

    it('ghép metadata cho cả từ bằng ĐÚNG một truy vấn', function (): void {
        // N+1 ở đây nhân với số lần người dùng mở một từ.
        $queries = 0;
        DB::listen(function ($q) use (&$queries): void {
            if (str_contains($q->sql, 'from "dictionary_characters"')) {
                $queries++;
            }
        });

        characters('学习', 'xue2 xi2');

        expect($queries)->toBe(1);
    });
});

describe('endpoint hình học nét', function (): void {
    it('trả strokes và medians', function (): void {
        $data = strokesApi('学')->assertOk()->json('data');

        expect($data['char'])->toBe('学')
            ->and($data['strokes'])->toHaveCount(8)
            ->and($data['medians'])->toHaveCount(8);
    });

    it('đặt Cache-Control immutable — dữ liệu không bao giờ đổi', function (): void {
        // Symfony sắp lại directive theo thứ tự chữ cái, nên giá trị kỳ vọng
        // KHÔNG giống thứ tự viết trong controller.
        strokesApi('学')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    });

    it('trả 404 cho chữ ngoài bộ dữ liệu', function (): void {
        strokesApi('龘')->assertNotFound();
    });

    it('chặn chuỗi không phải Hán ngay ở tầng route', function (): void {
        // Ràng buộc regex CJK, nên `/characters/abc/strokes` không khớp route
        // nào và KHÔNG chạm database — nếu không, endpoint thành một đường quét
        // bảng miễn phí cho mọi chuỗi người lạ gửi tới.
        test()->actingAs(test()->user, 'sanctum')
            ->getJson('/api/dictionary/characters/abc/strokes')
            ->assertNotFound();
    });
});
