<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function importFixture(array $options = []): int
{
    return Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
        ...$options,
    ]);
}

describe('parse và upsert', function (): void {
    it('import đúng số mục và bỏ qua header', function (): void {
        expect(importFixture())->toBe(0);

        // 14 dòng dữ liệu, 8 dòng header `#`.
        expect(DictionaryWord::count())->toBe(14);
    });

    it('chuẩn hóa đủ ba dạng pinyin', function (): void {
        importFixture();

        $word = DictionaryWord::where('simplified', '学习')->sole();

        expect($word->pinyin)->toBe('xuéxí')
            ->and($word->pinyin_numbered)->toBe('xue2 xi2')
            ->and($word->pinyin_plain)->toBe('xuexi')
            ->and($word->traditional)->toBe('學習');
    });

    it('tách nghĩa thành mảng và bản phẳng', function (): void {
        importFixture();

        $word = DictionaryWord::where('simplified', '学习')->sole();

        expect($word->definitions_en)->toBe(['to learn', 'to study'])
            ->and($word->definitions_en_text)->toBe('to learn; to study');
    });

    it('giữ riêng từng cách đọc của chữ đa âm', function (): void {
        importFixture();

        // 行 đọc `xíng` và `háng` là hai mục khác nhau — gộp chúng lại là làm
        // mất chính thứ mà người học cần phân biệt.
        $readings = DictionaryWord::where('simplified', '行')->pluck('pinyin')->sort()->values();

        expect($readings->all())->toBe(['háng', 'xíng']);
    });

    it('chuyển u: thành ü', function (): void {
        importFixture();

        expect(DictionaryWord::where('simplified', '女')->value('pinyin'))->toBe('nǚ')
            ->and(DictionaryWord::where('simplified', '女')->value('pinyin_plain'))->toBe('nu');
    });

    it('đánh dấu chữ đơn', function (): void {
        importFixture();

        expect(DictionaryWord::where('simplified', '好')->value('is_single_char'))->toBeTrue()
            ->and(DictionaryWord::where('simplified', '学习')->value('is_single_char'))->toBeFalse()
            ->and(DictionaryWord::where('simplified', '学习')->value('char_count'))->toBe(2);
    });
});

describe('chạy lại được', function (): void {
    it('không nhân bản bản ghi khi import lần hai', function (): void {
        importFixture();
        $first = DictionaryWord::count();

        importFixture();

        expect(DictionaryWord::count())->toBe($first);
    });

    it('KHÔNG ghi đè bản ghi đã sửa tay', function (): void {
        // D10: sửa tay âm Hán-Việt là công của con người, chỉ qua artisan trên
        // VPS. Import lại mà xóa mất nó thì mỗi lần cập nhật từ điển là một lần
        // mất sạch công rà soát, im lặng.
        importFixture();

        DictionaryWord::where('simplified', '学习')->update([
            'han_viet' => 'học tập',
            'han_viet_plain' => 'hoc tap',
            'han_viet_status' => DictionaryWord::STATUS_MANUAL,
        ]);

        importFixture();

        $word = DictionaryWord::where('simplified', '学习')->sole();

        expect($word->han_viet)->toBe('học tập')
            ->and($word->han_viet_status)->toBe(DictionaryWord::STATUS_MANUAL);
    });

    it('vẫn cập nhật nghĩa khi nguồn đổi', function (): void {
        importFixture();
        DictionaryWord::where('simplified', '学习')->update(['definitions_en_text' => 'nghĩa cũ']);

        importFixture();

        expect(DictionaryWord::where('simplified', '学习')->value('definitions_en_text'))
            ->toBe('to learn; to study');
    });
});

describe('gắn nhãn', function (): void {
    it('gắn hsk_level từ bộ old-1..6 của HSK 2.0', function (): void {
        importFixture();

        expect(DictionaryWord::where('simplified', '学习')->value('hsk_level'))->toBe(1)
            ->and(DictionaryWord::where('simplified', '银行')->value('hsk_level'))->toBe(3);
    });

    it('bỏ qua cấp new-* vì MVP chốt HSK 2.0', function (): void {
        importFixture();

        expect(DictionaryWord::where('simplified', '沙发')->value('hsk_level'))->toBeNull();
    });

    it('chỉ gắn HSK cho cách đọc phổ biến nhất của chữ đa âm', function (): void {
        importFixture();

        // 行 có hai cách đọc nhưng chỉ một dòng được gắn cấp — nếu không thì
        // một từ HSK 2 sẽ đếm thành hai.
        $tagged = DictionaryWord::where('simplified', '行')->whereNotNull('hsk_level')->count();

        expect($tagged)->toBe(1);
    });

    it('gắn frequency_rank theo thứ tự trong nguồn', function (): void {
        importFixture();

        expect(DictionaryWord::where('simplified', '好')->value('frequency_rank'))->toBe(1)
            ->and(DictionaryWord::where('simplified', '一')->value('frequency_rank'))->toBe(2);
    });

    it('gắn cùng một hạng cho mọi cách đọc của một mặt chữ', function (): void {
        importFixture();

        // Tần suất là thuộc tính của mặt chữ trong ngữ liệu, không tách theo âm.
        $ranks = DictionaryWord::where('simplified', '行')->pluck('frequency_rank')->unique();

        expect($ranks->all())->toBe([4]);
    });

    it('đặt is_priority cho hợp của HSK và ngưỡng tần suất', function (): void {
        importFixture();

        expect(DictionaryWord::where('simplified', '学习')->value('is_priority'))->toBeTrue()
            ->and(DictionaryWord::where('simplified', '沙发')->value('is_priority'))->toBeFalse();
    });

    it('bỏ qua từ HSK không có trong CC-CEDICT', function (): void {
        importFixture();

        expect(DictionaryWord::where('simplified', 'khong-co-trong-cedict')->exists())->toBeFalse();
    });
});

describe('hạ tầng full-text', function (): void {
    it('tạo được generated column dùng f_unaccent trên Postgres thật', function (): void {
        // Đây là finding H2 của red team: `unaccent()` là STABLE nên Postgres
        // TỪ CHỐI nó trong generated column. Test này chỉ có ý nghĩa khi chạy
        // trên Postgres thật — thêm một lý do CI không được dùng SQLite.
        importFixture();

        $tsv = DB::table('dictionary_words')
            ->where('simplified', '学习')
            ->value('search_tsv');

        expect($tsv)->toBeString()->not->toBeEmpty();
    });

    it('bỏ dấu tiếng Việt trong search_tsv để tìm không dấu vẫn khớp', function (): void {
        importFixture();

        DictionaryWord::where('simplified', '学习')->update(['han_viet' => 'học tập']);

        $matched = DB::table('dictionary_words')
            ->whereRaw("search_tsv @@ to_tsquery('simple', 'hoc')")
            ->where('simplified', '学习')
            ->exists();

        expect($matched)->toBeTrue();
    });
});
