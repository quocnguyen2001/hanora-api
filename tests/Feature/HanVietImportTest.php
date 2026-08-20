<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use Illuminate\Support\Facades\Artisan;

function importDictionaryAndHanViet(): void
{
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);

    Artisan::call('han-viet:import', [
        '--unihan' => base_path('tests/Fixtures/unihan-sample.txt'),
        '--supplement' => base_path('tests/Fixtures/hanviet-supplement-sample.csv'),
    ]);
}

describe('han-viet:import', function (): void {
    it('điền âm Hán-Việt cho từ ghép được', function (): void {
        importDictionaryAndHanViet();

        $word = DictionaryWord::where('simplified', '学习')->sole();

        expect($word->han_viet)->toBe('học tập')
            ->and($word->han_viet_plain)->toBe('hoc tap')
            ->and($word->han_viet_status)->toBe(DictionaryWord::STATUS_OK);
    });

    it('cho 银行 ra ngân hàng chứ không phải ngân hành', function (): void {
        // Đây là Success Criteria của P5, viết thành test để nó không lặng lẽ
        // hỏng khi đổi nguồn dữ liệu.
        importDictionaryAndHanViet();

        expect(DictionaryWord::where('simplified', '银行')->value('han_viet'))->toBe('ngân hàng');
    });

    it('để han_viet null khi không ghép được, không phải chuỗi rỗng', function (): void {
        importDictionaryAndHanViet();

        // 沙發 không có trong fixture bảng tra.
        $word = DictionaryWord::where('simplified', '沙发')->sole();

        expect($word->han_viet)->toBeNull()
            ->and($word->han_viet_plain)->toBeNull()
            ->and($word->han_viet_status)->toBe(DictionaryWord::STATUS_MISSING);
    });

    it('chạy lần hai không đổi kết quả', function (): void {
        importDictionaryAndHanViet();
        $before = DictionaryWord::orderBy('id')->pluck('han_viet', 'id');

        Artisan::call('han-viet:import', [
            '--unihan' => base_path('tests/Fixtures/unihan-sample.txt'),
            '--supplement' => base_path('tests/Fixtures/hanviet-supplement-sample.csv'),
        ]);

        expect(DictionaryWord::orderBy('id')->pluck('han_viet', 'id')->all())->toBe($before->all());
    });

    it('KHÔNG ghi đè bản ghi manual', function (): void {
        importDictionaryAndHanViet();

        DictionaryWord::where('simplified', '学习')->update([
            'han_viet' => 'âm sửa tay',
            'han_viet_status' => DictionaryWord::STATUS_MANUAL,
        ]);

        Artisan::call('han-viet:import', [
            '--unihan' => base_path('tests/Fixtures/unihan-sample.txt'),
            '--supplement' => base_path('tests/Fixtures/hanviet-supplement-sample.csv'),
        ]);

        expect(DictionaryWord::where('simplified', '学习')->value('han_viet'))->toBe('âm sửa tay');
    });
});

describe('han-viet:status — gate của P5', function (): void {
    it('trả exit code 0 khi độ phủ đạt ngưỡng', function (): void {
        importDictionaryAndHanViet();

        expect(Artisan::call('han-viet:status', ['--threshold' => 1]))->toBe(0);
    });

    it('trả exit code khác 0 khi độ phủ dưới ngưỡng', function (): void {
        // Gate phải chặn được bằng exit code, không chỉ in ra cho người đọc:
        // dưới ngưỡng thì ôn tập trắc nghiệm (D13) và nhánh tìm kiếm Hán-Việt
        // ở P6 đều mất chỗ dựa.
        importDictionaryAndHanViet();

        expect(Artisan::call('han-viet:status', ['--threshold' => 100]))->toBe(1);
    });

    it('tính manual vào độ phủ vì âm đó hiển thị được', function (): void {
        importDictionaryAndHanViet();
        DictionaryWord::where('is_priority', true)->update([
            'han_viet_status' => DictionaryWord::STATUS_MANUAL,
        ]);

        expect(Artisan::call('han-viet:status', ['--threshold' => 100]))->toBe(0);
    });
});

describe('dictionary:override — D10', function (): void {
    it('đặt âm sửa tay và khóa trạng thái manual', function (): void {
        importDictionaryAndHanViet();
        $id = DictionaryWord::where('simplified', '沙发')->value('id');

        expect(Artisan::call('dictionary:override', ['id' => $id, '--han-viet' => 'sa phát']))->toBe(0);

        $word = DictionaryWord::find($id);

        expect($word->han_viet)->toBe('sa phát')
            ->and($word->han_viet_plain)->toBe('sa phat')
            ->and($word->han_viet_status)->toBe(DictionaryWord::STATUS_MANUAL);
    });

    it('trả mục về pending với --reset', function (): void {
        importDictionaryAndHanViet();
        $id = DictionaryWord::where('simplified', '学习')->value('id');

        Artisan::call('dictionary:override', ['id' => $id, '--reset' => true]);

        expect(DictionaryWord::find($id)->han_viet_status)->toBe(DictionaryWord::STATUS_PENDING);
    });

    it('báo lỗi khi thiếu cả --han-viet lẫn --reset', function (): void {
        importDictionaryAndHanViet();
        $id = DictionaryWord::where('simplified', '学习')->value('id');

        expect(Artisan::call('dictionary:override', ['id' => $id]))->toBe(1);
    });

    it('báo lỗi khi id không tồn tại', function (): void {
        expect(Artisan::call('dictionary:override', ['id' => 999999, '--han-viet' => 'x']))->toBe(1);
    });
});
