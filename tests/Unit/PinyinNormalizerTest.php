<?php

declare(strict_types=1);

use App\Services\Dictionary\PinyinNormalizer;

beforeEach(function (): void {
    $this->normalizer = new PinyinNormalizer;
});

describe('toneMarked', function (): void {
    it('đặt dấu thanh đúng vị trí', function (string $input, string $expected): void {
        expect($this->normalizer->toneMarked($input))->toBe($expected);
    })->with([
        'hai âm tiết' => ['xue2 xi2', 'xuéxí'],
        'thanh 1' => ['ma1', 'mā'],
        'thanh 2' => ['ma2', 'má'],
        'thanh 3' => ['ma3', 'mǎ'],
        'thanh 4' => ['ma4', 'mà'],
        'thanh nhẹ số 5' => ['ma5', 'ma'],
        'thanh nhẹ không số' => ['de', 'de'],
        // Quy tắc: có `a` thì đánh vào `a`; không thì `o`/`e`; không nữa thì
        // nguyên âm cuối. `iu`/`ui` rơi vào nhánh nguyên âm cuối.
        'ưu tiên a' => ['hao3', 'hǎo'],
        'iu đánh vào u' => ['liu2', 'liú'],
        'ui đánh vào i' => ['gui4', 'guì'],
        'ao đánh vào a' => ['bao1', 'bāo'],
        'ou đánh vào o' => ['dou1', 'dōu'],
        'e đơn' => ['he1', 'hē'],
    ]);

    it('chuyển ký hiệu u: của CC-CEDICT thành ü', function (string $input, string $expected): void {
        expect($this->normalizer->toneMarked($input))->toBe($expected);
    })->with([
        'nu:3 là 女' => ['nu:3', 'nǚ'],
        'lu:4 là 律' => ['lu:4', 'lǜ'],
        'nu:e4' => ['nu:e4', 'nüè'],
        'v thay cho ü' => ['nv3', 'nǚ'],
    ]);

    it('giữ nguyên r hóa và ký tự không phải pinyin', function (): void {
        expect($this->normalizer->toneMarked('er2'))->toBe('ér')
            ->and($this->normalizer->toneMarked('quan1 r5'))->toBe('quānr');
    });

    it('giữ chữ hoa của danh từ riêng', function (): void {
        expect($this->normalizer->toneMarked('Qi1 xi1'))->toBe('Qīxī');
    });

    it('không nuốt token không phải pinyin', function (): void {
        // CC-CEDICT có mục như `xue2 sheng5 t jian3 yan4` (Student's t-test).
        expect($this->normalizer->toneMarked('xue2 sheng5 t jian3 yan4'))
            ->toBe('xuéshengtjiǎnyàn');
    });

    it('trả chuỗi rỗng cho input rỗng', function (): void {
        expect($this->normalizer->toneMarked(''))->toBe('')
            ->and($this->normalizer->toneMarked('   '))->toBe('');
    });
});

describe('plain', function (): void {
    it('bỏ dấu thanh, số và khoảng trắng', function (string $input, string $expected): void {
        expect($this->normalizer->plain($input))->toBe($expected);
    })->with([
        'từ dạng có dấu' => ['xuéxí', 'xuexi'],
        'từ dạng số' => ['xue2xi2', 'xuexi'],
        'từ dạng số có cách' => ['xue2 xi2', 'xuexi'],
        'ü thành u' => ['nǚ', 'nu'],
        'u: thành u' => ['nu:3', 'nu'],
        'hạ chữ hoa' => ['Qīxī', 'qixi'],
        'rỗng' => ['', ''],
    ]);

    it('KHÔNG tự đổi v thành u, vì v là chữ cái thật trong tiếng Việt', function (): void {
        // `v` thay cho `ü` chỉ đúng trong ngữ cảnh pinyin. `plain()` nhận cả
        // chuỗi người dùng gõ, và đổi mù quáng sẽ biến `việt` thành `uiet`.
        expect($this->normalizer->plain('việt'))->toBe('viet')
            ->and($this->normalizer->plain('nv3'))->toBe('nv');
    });

    it('quy được nv3 về nu khi đi qua dạng có dấu trước', function (): void {
        // Đây là đường mà import dùng để sinh `pinyin_plain`: dạng số → dạng có
        // dấu → dạng trần. `v` được hiểu là `ü` ở bước âm tiết, nơi ngữ cảnh
        // chắc chắn là pinyin.
        expect($this->normalizer->plain($this->normalizer->toneMarked('nv3')))->toBe('nu');
    });

    it('cho ba dạng của cùng một từ ra cùng một kết quả', function (): void {
        // R5 trong plan: tìm kiếm và chấm bài phải quy về cùng một chuẩn, nếu
        // không thì gõ đúng vẫn bị chấm sai.
        $formats = ['xuéxí', 'xue2xi2', 'xue2 xi2', 'XUEXI', 'xuexi'];

        expect(array_unique(array_map(
            fn (string $f) => $this->normalizer->plain($f),
            $formats
        )))->toHaveCount(1);
    });

    it('xử lý được input tiếng Việt có dấu mà không ném lỗi', function (string $input, string $expected): void {
        // P6 đẩy thẳng chuỗi người dùng gõ vào hàm này. Người Việt sẽ gõ tiếng
        // Việt. Hàm phải trả kết quả xác định chứ không được throw.
        expect($this->normalizer->plain($input))->toBe($expected);
    })->with([
        'học tập' => ['học tập', 'hoctap'],
        'ngân hàng' => ['ngân hàng', 'nganhang'],
        'đông tây' => ['đông tây', 'dongtay'],
        'chữ đ hoa' => ['Đông', 'dong'],
        'đủ dấu' => ['ạ ả ã á à â ê ô ơ ư', 'aaaaaaeoou'],
    ]);

    it('bỏ dấu câu và ký tự lạ', function (): void {
        expect($this->normalizer->plain('xué-xí!'))->toBe('xuexi')
            ->and($this->normalizer->plain('学习'))->toBe('');
    });
});

describe('stripDiacritics', function (): void {
    it('bỏ dấu nhưng giữ khoảng trắng', function (): void {
        // P5 dùng cho `han_viet_plain`, nơi khoảng trắng phải còn để `hoc tap`
        // vẫn là hai tiếng.
        expect($this->normalizer->stripDiacritics('học tập'))->toBe('hoc tap')
            ->and($this->normalizer->stripDiacritics('ngân hàng'))->toBe('ngan hang');
    });
});
