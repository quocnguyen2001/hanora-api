<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Services\Dictionary\HanVietComposer;
use App\Services\Dictionary\HanVietReadingTable;
use App\Services\Dictionary\PinyinNormalizer;
use App\Services\Dictionary\UnihanReadingParser;

beforeEach(function (): void {
    $unihan = (new UnihanReadingParser)->parse(dirname(__DIR__).'/Fixtures/unihan-sample.txt');
    $table = new HanVietReadingTable(dirname(__DIR__).'/Fixtures/hanviet-supplement-sample.csv', $unihan);

    $this->table = $table;
    $this->composer = new HanVietComposer($table, new PinyinNormalizer);
});

describe('ghép cơ bản', function (): void {
    it('ghép từ hai ký tự', function (): void {
        expect($this->composer->compose('學習', 'xue2 xi2'))->toBe([
            'han_viet' => 'học tập',
            'han_viet_plain' => 'hoc tap',
            'han_viet_status' => DictionaryWord::STATUS_OK,
        ]);
    });

    it('ghép chữ đơn', function (): void {
        expect($this->composer->compose('西', 'xi1')['han_viet'])->toBe('tây');
    });

    it('bỏ dấu nhưng giữ khoảng trắng cho han_viet_plain', function (): void {
        // `hoc tap` phải còn là hai tiếng để prefix match theo tiếng chạy được ở P6.
        expect($this->composer->compose('銀行', 'yin2 hang2')['han_viet_plain'])->toBe('ngan hang');
    });
});

describe('chữ đa âm — R6 trong plan', function (): void {
    it('phân giải đúng theo âm tiết pinyin của chính mục từ', function (string $traditional, string $pinyin, string $expected): void {
        expect($this->composer->compose($traditional, $pinyin)['han_viet'])->toBe($expected);
    })->with([
        // Ca chính của Success Criteria: 银行 phải ra `ngân hàng`, KHÔNG phải
        // `ngân hành`. Unihan một mình không làm được — nó chỉ cho 行 một âm.
        '銀行 -> ngân hàng' => ['銀行', 'yin2 hang2', 'ngân hàng'],
        '行走 -> hành tẩu' => ['行走', 'xing2 zou3', 'hành tẩu'],
        '長大 -> trưởng đại' => ['長大', 'zhang3 da4', 'trưởng đại'],
        '重要 -> trọng yếu' => ['重要', 'zhong4 yao4', 'trọng yếu'],
        '東西 -> đông tây' => ['東西', 'dong1 xi1', 'đông tây'],
    ]);

    it('cùng một chữ cho hai âm khác nhau tùy pinyin', function (): void {
        expect($this->composer->compose('行', 'hang2')['han_viet'])->toBe('hàng')
            ->and($this->composer->compose('行', 'xing2')['han_viet'])->toBe('hành');
    });
});

describe('nguồn nào thắng', function (): void {
    it('ưu tiên bảng bổ sung hơn Unihan khi hai nguồn bất đồng', function (): void {
        // Hồi quy cho một bug thật: Unihan cho 東 hai âm `đang đông`, bảng bổ
        // sung cho đúng một âm `đông`. Hợp hai tập lại khiến 東西 bị đánh
        // `ambiguous` và biến mất khỏi giao diện, dù nguồn tốt hơn đã trả lời
        // dứt khoát. `đang` là âm cổ hiếm, không nên tạo nhập nhằng.
        expect($this->table->lookup('東'))->toBe('đông')
            ->and($this->composer->compose('東西', 'dong1 xi1')['han_viet_status'])
            ->toBe(DictionaryWord::STATUS_OK);
    });

    it('rơi về Unihan khi bảng bổ sung không có ký tự', function (): void {
        // 走 chỉ có trong cả hai; 大 có trong cả hai. Dùng ký tự chỉ Unihan mới có.
        expect($this->table->lookup('行', 'heng2'))->not->toBeFalse();
    });
});

describe('không đoán bừa', function (): void {
    it('trả missing khi có ký tự không nguồn nào biết', function (): void {
        $result = $this->composer->compose('學鿕', 'xue2 mou3');

        expect($result['han_viet'])->toBeNull()
            ->and($result['han_viet_status'])->toBe(DictionaryWord::STATUS_MISSING);
    });

    it('trả ambiguous khi nhiều âm mà pinyin không phân giải được', function (): void {
        // 行 có `hàng` và `hành`; âm tiết `wu1` không khớp cặp nào.
        $result = $this->composer->compose('行', 'wu1');

        expect($result['han_viet'])->toBeNull()
            ->and($result['han_viet_status'])->toBe(DictionaryWord::STATUS_AMBIGUOUS);
    });

    it('bỏ hẳn pinyin khi số âm tiết không khớp số ký tự', function (): void {
        // Ghép lệch sẽ tra 行 bằng âm tiết của ký tự bên cạnh và cho ra âm sai
        // một cách tự tin. Thà mất khả năng phân giải còn hơn phân giải sai.
        $result = $this->composer->compose('行走', 'xing2');

        expect($result['han_viet_status'])->toBe(DictionaryWord::STATUS_AMBIGUOUS);
    });

    it('không bao giờ trả chuỗi rỗng thay cho null', function (): void {
        // FE dựa vào null để ẩn hẳn dòng Hán-Việt. Chuỗi rỗng sẽ render ra một
        // dòng trống trông như lỗi giao diện.
        $result = $this->composer->compose('鿕', 'mou3');

        expect($result['han_viet'])->toBeNull()
            ->and($result['han_viet_plain'])->toBeNull();
    });
});
