<?php

declare(strict_types=1);

use App\Services\Dictionary\MakeMeAHanziParser;

beforeEach(function (): void {
    $this->parser = new MakeMeAHanziParser;
    $this->path = sys_get_temp_dir().'/mmah-'.uniqid().'.txt';
});

afterEach(function (): void {
    // `file_exists` chứ không `@unlink`: ca "file không đọc được" không tạo file
    // nào, và PHPUnit vẫn bắt được warning dù toán tử `@` đã chặn nó ở tầng PHP.
    if (file_exists($this->path)) {
        unlink($this->path);
    }
});

function writeLines(string $path, string ...$lines): void
{
    file_put_contents($path, implode("\n", $lines)."\n");
}

describe('dictionary()', function (): void {
    it('quy `？` về null khi nó là CẢ giá trị', function (): void {
        // 66 chữ trong nguồn có `decomposition: "？"`. Để nguyên thì màn hình
        // hiện "Hình thái: ？", đọc ra như dữ liệu hỏng.
        writeLines($this->path, '{"character":"⺈","decomposition":"？","radical":"⺈"}');

        $rows = iterator_to_array($this->parser->dictionary($this->path));

        expect($rows[0]['decomposition'])->toBeNull();
    });

    it('GIỮ NGUYÊN `？` nhúng giữa IDS', function (): void {
        // `⿹？冫` vẫn nói được cấu trúc chữ (bao từ trên-phải, có 冫), chỉ thiếu
        // tên một thành phần. Đo được 383 chữ như vậy — vứt chúng đi để tránh
        // một ký tự lạ là mất thông tin thật.
        writeLines($this->path, '{"character":"习","decomposition":"⿹？冫","radical":"冫"}');

        $rows = iterator_to_array($this->parser->dictionary($this->path));

        expect($rows[0]['decomposition'])->toBe('⿹？冫');
    });

    it('bỏ loại lục thư lạ thay vì đẩy chuỗi tiếng Anh lên màn hình', function (): void {
        // Nguồn hiện có ba loại. Thêm loại thứ tư thì nó rơi về `null` và FE ẩn
        // dòng — tốt hơn là hiện một chuỗi mà bảng ánh xạ không có.
        writeLines(
            $this->path,
            '{"character":"甲","radical":"田","etymology":{"type":"loangraph"}}',
            '{"character":"乙","radical":"乙","etymology":{"type":"pictographic"}}',
        );

        $rows = iterator_to_array($this->parser->dictionary($this->path));

        expect($rows[0]['etymology_type'])->toBeNull()
            ->and($rows[1]['etymology_type'])->toBe('pictographic');
    });

    it('bỏ dòng hỏng mà không dừng cả lượt import', function (): void {
        writeLines(
            $this->path,
            '{"character":"一","radical":"一"}',
            'không phải JSON',
            '{"character":"二","radical":"二"}',
        );

        expect(iterator_to_array($this->parser->dictionary($this->path)))->toHaveCount(2);
    });
});

describe('graphics()', function (): void {
    it('suy stroke_count từ chính mảng nét', function (): void {
        // Nguồn không có trường số nét; đếm mảng nét là định nghĩa đúng.
        writeLines(
            $this->path,
            '{"character":"二","strokes":["M 1","M 2"],"medians":[[[1,2]],[[3,4]]]}',
        );

        $rows = iterator_to_array($this->parser->graphics($this->path));

        expect($rows[0]['stroke_count'])->toBe(2);
    });

    it('bỏ chữ không có nét', function (): void {
        writeLines($this->path, '{"character":"〇","strokes":[],"medians":[]}');

        expect(iterator_to_array($this->parser->graphics($this->path)))->toBe([]);
    });
});

it('ném khi file không đọc được', function (): void {
    // Sai đường dẫn phải nổ ngay, không âm thầm import 0 chữ rồi báo thành công.
    expect(fn () => iterator_to_array($this->parser->dictionary('/khong/ton/tai.txt')))
        ->toThrow(RuntimeException::class);
});
