<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use Illuminate\Support\Facades\Artisan;

function importDictionary(): int
{
    return Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);
}

function importCvdict(array $options = []): int
{
    return Artisan::call('cvdict:import', [
        '--path' => base_path('tests/Fixtures/cvdict-sample.u8'),
        '--skip-checksum' => true,
        ...$options,
    ]);
}

/**
 * @return array{0: list<string>|null, 1: string|null}
 */
function viOf(string $simplified, ?string $pinyinNumbered = null): array
{
    $word = DictionaryWord::where('simplified', $simplified)
        ->when($pinyinNumbered !== null, fn ($q) => $q->where('pinyin_numbered', $pinyinNumbered))
        ->sole();

    return [$word->definitions_vi, $word->definitions_vi_text];
}

describe('gắn nghĩa tiếng Việt', function (): void {
    beforeEach(function (): void {
        importDictionary();
    });

    it('gắn nghĩa vào đúng dòng, giữ thứ tự và bản phẳng', function (): void {
        expect(importCvdict())->toBe(0);

        expect(viOf('学习'))->toBe([['học tập', 'nghiên cứu'], 'học tập; nghiên cứu']);
    });

    it('phân biệt hai cách đọc của cùng một chữ', function (): void {
        // Khóa là (giản thể, pinyin số), KHÔNG phải chữ giản thể một mình. Đây
        // chính là lý do CVDICT được chọn thay vì một nguồn chỉ có chữ: gộp
        // `xíng` với `háng` là gán "đi" cho "hãng buôn".
        importCvdict();

        expect(viOf('行', 'xing2')[0])->toBe(['đi', 'đi bộ'])
            ->and(viOf('行', 'hang2')[0])->toBe(['hàng', 'dòng', 'hãng buôn']);
    });

    it('để null cho dòng không khớp — KHÔNG phải mảng rỗng', function (): void {
        // `null` = "nguồn không có từ này"; `[]` = "có nhưng không nghĩa nào".
        // FE phân biệt hai thứ đó: `null` thì ẩn hẳn phần nghĩa Việt.
        importCvdict();

        expect(viOf('银'))->toBe([null, null])
            ->and(viOf('女'))->toBe([null, null]);
    });

    it('KHÔNG tạo dòng từ điển mới cho mục CVDICT thừa', function (): void {
        $before = DictionaryWord::count();

        importCvdict();

        // 猫 có trong CVDICT nhưng không có trong từ điển. Tạo dòng cho nó là
        // tạo một mục không có nghĩa tiếng Anh, không pinyin có dấu, không tần
        // suất — `dictionary:import` sở hữu việc tạo dòng, lệnh này thì không.
        expect(DictionaryWord::count())->toBe($before)
            ->and(DictionaryWord::where('simplified', '猫')->exists())->toBeFalse();
    });

    it('gộp nghĩa của các khóa trùng trong nguồn', function (): void {
        importCvdict();

        // 好 xuất hiện hai lần. Giữ lại một dòng là im lặng làm mất nghĩa;
        // chọn bừa một dòng là kết quả không xác định giữa hai lần chạy.
        expect(viOf('好')[0])->toBe(['tốt', 'hay']);
    });

    it('chạy hai lần cho kết quả giống hệt', function (): void {
        importCvdict();
        $first = DictionaryWord::orderBy('id')->pluck('definitions_vi_text', 'id')->all();

        importCvdict();
        $second = DictionaryWord::orderBy('id')->pluck('definitions_vi_text', 'id')->all();

        expect($second)->toBe($first);
    });

    it('KHÔNG đụng một dòng definitions_en nào', function (): void {
        // Nghĩa tiếng Anh là cơ chế đối chiếu duy nhất người học có khi nghi ngờ
        // một nghĩa dịch máy. Ghi đè nó là gỡ mất chốt chặn đó — cùng loại bảo
        // vệ mà `dictionary:import` dùng cho `han_viet`.
        $before = DictionaryWord::orderBy('id')->pluck('definitions_en_text', 'id')->all();

        importCvdict();

        expect(DictionaryWord::orderBy('id')->pluck('definitions_en_text', 'id')->all())->toBe($before);
    });
});

describe('chốt chặn', function (): void {
    it('SHA-256 sai thì DỪNG, không ghi dòng nào', function (): void {
        importDictionary();

        // Fixture cố tình không phải file nguồn thật, nên checksum lệch.
        expect(importCvdict(['--skip-checksum' => false]))->toBe(1)
            ->and(DictionaryWord::whereNotNull('definitions_vi')->count())->toBe(0);
    });

    it('0 dòng cập nhật là THẤT BẠI, không phải thành công', function (): void {
        // Từ điển rỗng — nghĩa là quên `dictionary:import`. Exit 0 ở đây sẽ để
        // runbook đi tiếp với một app không có nghĩa tiếng Việt nào.
        expect(importCvdict())->toBe(1);
    });

    it('không đọc được file thì THẤT BẠI', function (): void {
        importDictionary();

        expect(importCvdict(['--path' => base_path('tests/Fixtures/khong-ton-tai.u8')]))->toBe(1);
    });
});

describe('cvdict:status', function (): void {
    beforeEach(function (): void {
        importDictionary();
    });

    it('FAIL khi độ phủ tập ưu tiên dưới ngưỡng', function (): void {
        DictionaryWord::query()->update(['is_priority' => true]);

        importCvdict();

        // Fixture chỉ phủ 6/14 dòng — dưới 95%.
        expect(Artisan::call('cvdict:status'))->toBe(1);
    });

    it('PASS khi tập ưu tiên đã đủ độ phủ', function (): void {
        DictionaryWord::query()->update(['is_priority' => false]);
        // 行 chứ không phải 东西: fixture chỉ phủ 东西 `dong1 xi5`, còn
        // `dong1 xi1` là dòng riêng và sẽ kéo độ phủ xuống 75%.
        DictionaryWord::whereIn('simplified', ['学习', '学生', '行'])->update(['is_priority' => true]);

        importCvdict();

        expect(Artisan::call('cvdict:status'))->toBe(0);
    });

    it('FAIL khi từ điển chưa import', function (): void {
        DictionaryWord::query()->delete();

        expect(Artisan::call('cvdict:status'))->toBe(1);
    });
});
