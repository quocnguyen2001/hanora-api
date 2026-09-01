<?php

declare(strict_types=1);

use App\Services\Dictionary\CedictParser;
use App\Services\Dictionary\PinyinNormalizer;

beforeEach(function (): void {
    $this->parser = new CedictParser(new PinyinNormalizer);
});

describe('tách lượng từ khỏi nghĩa', function (): void {
    it('rút CL: khi nó là một nghĩa ĐỘC LẬP', function (): void {
        // Dạng phổ biến nhất trong nguồn: `/bank/CL:家[jia1],個|个[ge4]/`.
        // Nghĩa đó rỗng hẳn sau khi rút nên phải biến mất khỏi `definitions_en`.
        $entry = $this->parser->parseLine('銀行 银行 [yin2 hang2] /bank/CL:家[jia1],個|个[ge4]/');

        expect($entry['definitions_en'])->toBe(['bank']);
        expect($entry['measure_words'])->toBe([
            ['simplified' => '家', 'traditional' => '家', 'pinyin' => 'jiā'],
            ['simplified' => '个', 'traditional' => '個', 'pinyin' => 'gè'],
        ]);
    });

    it('rút CL: khi nó NHÚNG trong ngoặc giữa nghĩa', function (): void {
        // `cat (CL:隻|只[zhi1])` để lại `cat ()` nếu chỉ xoá mà không dọn — một
        // cặp ngoặc rỗng trên màn hình đọc ra như dữ liệu hỏng.
        $entry = $this->parser->parseLine('貓 猫 [mao1] /cat (CL:隻|只[zhi1])/');

        expect($entry['definitions_en'])->toBe(['cat']);
        expect($entry['measure_words'])->toBe([
            ['simplified' => '只', 'traditional' => '隻', 'pinyin' => 'zhī'],
        ]);
    });

    it('rút được nhiều lượng từ trong một mục', function (): void {
        $entry = $this->parser->parseLine('狗 狗 [gou3] /dog/CL:隻|只[zhi1],條|条[tiao2]/');

        expect($entry['definitions_en'])->toBe(['dog']);
        expect(array_column($entry['measure_words'], 'simplified'))->toBe(['只', '条']);
    });

    it('trả null cho mục không có lượng từ', function (): void {
        // `null` chứ không mảng rỗng: cột jsonb nullable, và `null` đọc ra là
        // "từ này không có lượng từ" — cùng quy ước `definitions_vi` đang giữ.
        $entry = $this->parser->parseLine('學習 学习 [xue2 xi2] /to learn/to study/');

        expect($entry['measure_words'])->toBeNull();
        expect($entry['definitions_en'])->toBe(['to learn', 'to study']);
    });

    it('KHÔNG nhầm chữ CL trong nghĩa thường với mã lượng từ', function (): void {
        // Neo regex vào hình dạng `chữ[pinyin số]`, không chỉ vào hai chữ `CL:`.
        // Thiếu chốt này thì một nghĩa chứa "CL:" ở ngữ cảnh khác bị nuốt mất.
        $entry = $this->parser->parseLine('測試 测试 [ce4 shi4] /CL: an abbreviation/test/');

        expect($entry['definitions_en'])->toBe(['CL: an abbreviation', 'test']);
        expect($entry['measure_words'])->toBeNull();
    });

    it('khử trùng lặp lượng từ xuất hiện ở hai nghĩa', function (): void {
        $entry = $this->parser->parseLine('東西 东西 [dong1 xi5] /thing (CL:個|个[ge4])/stuff/CL:個|个[ge4]/');

        expect(array_column($entry['measure_words'], 'simplified'))->toBe(['个']);
        expect($entry['definitions_en'])->toBe(['thing', 'stuff']);
    });

    it('bỏ hẳn mục mà MỌI nghĩa đều là lượng từ', function (): void {
        // Sau khi rút thì không còn nghĩa nào để dạy. Nó phải bị loại như mọi
        // mục rỗng khác, không đi vào bảng với `definitions_en: []`.
        expect($this->parser->parseLine('個 个 [ge4] /CL:件[jian4]/'))->toBeNull();
    });

    it('không để CL: lọt vào text dùng cho vector tìm kiếm', function (): void {
        // `definitions_en_text` phải dựng TỪ danh sách đã dọn. Dựng từ chuỗi gốc
        // thì gõ "cl" ra kết quả rác dù màn hình trông sạch.
        $entry = $this->parser->parseLine('銀行 银行 [yin2 hang2] /bank/CL:家[jia1],個|个[ge4]/');

        expect($entry['definitions_en_text'])->not->toContain('CL:');
    });
});

describe('đường tiếng Việt', function (): void {
    it('dọn CL: khỏi nghĩa Việt nhưng KHÔNG trả lượng từ', function (): void {
        // `definitions_vi` cũng được hiển thị, nên mã `CL:` rò ra đó cũng là rò.
        // Nhưng cột `measure_words` thuộc sở hữu của `dictionary:import` —
        // `cvdict:import` không được đụng vào, cùng ranh giới mà pinyin đang giữ.
        $entry = $this->parser->parseVietnameseLine('貓 猫 [mao1] /con mèo (CL:隻|只[zhi1])/');

        expect($entry['definitions_vi'])->toBe(['con mèo']);
        expect($entry)->not->toHaveKey('measure_words');
    });
});
