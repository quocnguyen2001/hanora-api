<?php

declare(strict_types=1);

use App\Services\Dictionary\Glosses\GlossPrompt;

/**
 * `clean()` là chốt chặn thật, không phải hình thức: prompt CẤM chữ Hán và mã
 * pinyin, và model vẫn để lọt. Nghĩa bẩn ghi vào cột tìm kiếm sẽ nằm đó tới lần
 * chạy lại tiếp theo.
 */
function clean(mixed $raw): array
{
    return (new GlossPrompt)->clean($raw);
}

it('loại nghĩa còn lẫn chữ Hán', function (): void {
    expect(clean(['bạn', 'dùng trong 嗎啡']))->toBe(['bạn']);
});

it('gỡ mã pinyin rồi loại luôn cụm nếu chỉ còn chữ Hán', function (): void {
    // `您[nin2]` → gỡ mã trước, còn `您` là chữ Hán → rụng cả cụm.
    expect(clean(['khác với 您[nin2]', 'bạn']))->toBe(['bạn']);
});

it('gỡ mã pinyin nhưng giữ phần tiếng Việt còn lại', function (): void {
    expect(clean(['xe taxi [di1]']))->toBe(['xe taxi']);
});

it('gộp khoảng trắng thừa', function (): void {
    expect(clean(["của  \n  tôi"]))->toBe(['của tôi']);
});

it('chuyển về chữ thường và khử trùng', function (): void {
    expect(clean(['Của', 'của', 'CỦA']))->toBe(['của']);
});

it('cắt còn tối đa 8 nghĩa', function (): void {
    expect(clean(array_map(fn (int $i): string => "nghĩa {$i}", range(1, 20))))->toHaveCount(8);
});

it('bỏ phần tử rỗng và phần tử không phải chuỗi', function (): void {
    expect(clean(['bạn', '', '   ', 42, null, ['x']]))->toBe(['bạn']);
});

it('không ném với đầu vào không phải mảng', function (): void {
    expect(clean('đáng lẽ là mảng'))->toBe([])
        ->and(clean(null))->toBe([]);
});
