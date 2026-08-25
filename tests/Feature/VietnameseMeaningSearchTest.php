<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\User;
use App\Models\UserWord;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

/**
 * Nhánh tìm theo nghĩa tiếng Việt (rank 6 + `precision`) và bộ lọc kho từ.
 *
 * Nạp fixture RIÊNG chồng lên fixture chung. Không sửa `cedict-sample.u8`: bảy
 * test file nạp nó, `DictionaryImportTest` chốt số dòng, `HanVietImportTest`
 * chốt exit code của gate độ phủ, và `ReviewTest` bốc distractor ngẫu nhiên từ
 * cùng bảng — thêm từ vào đó là biến ba test thành FLAKY chứ không phải đỏ dứt
 * khoát.
 *
 * Nghĩa tiếng Việt trong `cvdict-sample-search.u8` lấy NGUYÊN VĂN từ nguồn
 * thật, không viết tay. Nghĩa tự chế sẽ làm test xanh trên dữ liệu không tồn
 * tại — và ca `xin chào` bên dưới chỉ có ý nghĩa vì nghĩa thứ 8 của 好 thật sự
 * chứa cụm đó.
 */
beforeEach(function (): void {
    foreach (['cedict-sample.u8', 'cedict-vi-sample.u8'] as $fixture) {
        Artisan::call('dictionary:import', [
            '--path' => base_path("tests/Fixtures/{$fixture}"),
            '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
            '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
        ]);
    }

    Artisan::call('han-viet:import', [
        '--unihan' => base_path('tests/Fixtures/unihan-sample.txt'),
        '--supplement' => base_path('tests/Fixtures/hanviet-supplement-sample.csv'),
    ]);

    Artisan::call('cvdict:import', [
        '--path' => base_path('tests/Fixtures/cvdict-sample-search.u8'),
        '--skip-checksum' => true,
    ]);

    $this->user = User::factory()->create();
});

function viSearch(string $query, ?string $mode = null): TestResponse
{
    return test()->actingAs(test()->user, 'sanctum')
        ->getJson('/api/dictionary/search?'.http_build_query(array_filter([
            'q' => $query,
            'mode' => $mode,
        ])));
}

function viSimplified(string $query, ?string $mode = null): array
{
    return collect(viSearch($query, $mode)->json('data'))->pluck('simplified')->all();
}

describe('nghĩa tiếng Việt ra vị trí 1', function (): void {
    it('đưa từ đúng lên đầu', function (string $query, string $expected): void {
        expect(viSimplified($query, 'vi')[0] ?? null)->toBe($expected);
    })->with([
        ['xin chào', '你好'],
        ['tạm biệt', '再见'],
        ['nước', '水'],
        ['chó', '狗'],
        ['mèo', '猫'],
        ['con mèo', '猫'],
        ['máy tính', '电脑'],
        ['may tinh', '电脑'],
        ['cam on', '谢谢'],
        ['hoc sinh', '学生'],
        // Gõ KHÔNG DẤU sau loại từ — kiểu gõ phổ biến nhất của người Việt.
        ['con meo', '猫'],
    ]);

    it('nghĩa TRỌN VẸN thắng nghĩa chỉ chứa từ khóa', function (): void {
        /*
         * Nghĩa thứ 8 của 好 là "(sau đại từ nhân xưng) xin chào", nên 好 KHỚP
         * `xin chào` thật. Nó từng đứng đầu vì `frequency_rank` của nó rất tốt.
         *
         * `precision` 0 — có nghĩa trọn vẹn bằng đúng truy vấn — là thứ duy nhất
         * phân xử được: 你好 có nghĩa đầu ĐÚNG BẰNG "xin chào", 好 thì chỉ chứa
         * cụm đó bên trong một nghĩa dài. Bỏ `precision` đi là 好 quay lại đầu.
         */
        $results = viSimplified('xin chào', 'vi');

        expect(array_search('你好', $results, true))
            ->toBeLessThan(array_search('好', $results, true));
    });

    it('bỏ loại từ đứng đầu để lấy trung tâm ngữ', function (): void {
        // `plainto_tsquery` nối các tiếng bằng AND, mà nghĩa của 猫 không chứa
        // `con` — không bỏ loại từ thì 猫 KHÔNG khớp gì cả.
        expect(viSimplified('con mèo', 'vi'))->toContain('猫');
    });

    it('GIỮ loại từ khi nó là một phần của nghĩa từ điển', function (): void {
        /*
         * `quả táo` là ca đối kháng của việc bỏ loại từ: 苹果 có nghĩa TRỌN VẸN
         * "quả táo", nên bỏ `quả` sẽ hạ nó xuống ngang hàng với mọi từ chứa
         * `táo` — trong đó 清醒 ("tỉnh táo") đứng trước theo tần suất.
         *
         * Bậc 0 so với truy vấn GỐC, không phải truy vấn đã bỏ loại từ. Đó là
         * thứ giữ cho hai ca này không loại trừ nhau.
         */
        expect(viSimplified('quả táo', 'vi')[0])->toBe('苹果');
    });

    it('KHÔNG kéo về từ chỉ khớp qua âm Hán-Việt bỏ dấu', function (): void {
        /*
         * Cầu nối cũ dịch `con mèo` thành từ khóa `cat`, và `cat` khớp 吃 vì âm
         * Hán-Việt của nó là `cật`, bỏ dấu thành `cat` — 吃 có `frequency_rank`
         * tốt hơn 猫 nên đứng trước.
         *
         * `search_vi_tsv` dựng TỪ MỖI `definitions_vi_text` nên cả lớp lỗi đó
         * biến mất theo cấu trúc, không phải bị vá.
         */
        expect(viSimplified('con mèo', 'vi'))->not->toContain('吃');
    });
});

describe('hai vector — có dấu và không dấu', function (): void {
    it('truy vấn CÓ DẤU không rơi vào bẫy bỏ dấu', function (string $query, string $notExpected): void {
        /*
         * Nguyên mẫu đầu bỏ dấu cả hai vế và cho ra rác đo được: `chó`→你/他/吗,
         * `bàn`→你/我们, `táo`→么/远/秀 — vì `cho`, `ban`, `tao` có mặt khắp nơi
         * trong định nghĩa tiếng Việt.
         *
         * Nếu ai gộp hai vector lại thành một, ba ca này đỏ ngay.
         */
        expect(viSimplified($query, 'vi'))->not->toContain($notExpected);
    })->with([
        ['chó', '你'],
        ['chó', '吃'],
        ['táo', '一'],
    ]);

    it('truy vấn KHÔNG dấu vẫn tìm được qua vector dự phòng', function (): void {
        // Đây là cả lý do vector không dấu tồn tại. Bỏ nó đi thì `may tinh`
        // không ra gì.
        expect(viSimplified('may tinh', 'vi'))->toContain('电脑');
    });

    it('không để khớp CÓ DẤU ngẫu nhiên chen lên trước truy vấn không dấu', function (): void {
        /*
         * Trên dữ liệu thật, `may tinh` khớp `may` và `tinh` CÓ DẤU trong nghĩa
         * của 吉凶 ("may mắn hay xui xẻo") và 吉凶 lên trước 电脑.
         *
         * Mỗi truy vấn đi ĐÚNG MỘT vector, chọn theo việc nó có dấu hay không.
         * Chạy cả hai rồi xếp bậc là mời chính lỗi đó quay lại.
         */
        expect(viSimplified('may tinh', 'vi')[0])->toBe('电脑');
    });
});

describe('truy vấn không mang tín hiệu', function (): void {
    it('bỏ qua truy vấn quá ngắn', function (string $query): void {
        // `de`, `va`, `ma` là hư từ tiếng Việt bỏ dấu. Ngưỡng độ dài tối thiểu
        // chặn chúng trước khi chạm vào index, nên không cần danh sách hư từ
        // riêng cho tầng này.
        expect(viSimplified($query, 'vi'))->toBeEmpty();
    })->with(['de', 'va', 'ma']);

    it('không khớp nghĩa nào thì phát hint', function (): void {
        expect(viSearch('zzzqqq', 'vi')->json('meta.hint'))->toBe('hv_not_found');
    });
});

describe('quan hệ với nhánh pinyin', function (): void {
    it('hạ pinyin xuống khi truy vấn CÓ DẤU và nghĩa Việt khớp được', function (): void {
        /*
         * `xin chào` bỏ dấu bỏ cách thành `xinchao`, đúng bằng `pinyin_plain` của
         * 新潮 — nhánh pinyin khớp chính xác là rank 3 nên 新潮 đứng trước 你好.
         *
         * Test này cũng khóa luôn một bug tinh vi: `rank` được bind qua PDO dưới
         * dạng CHUỖI, nên Postgres suy ra kiểu `text` và `ORDER BY rank` sắp theo
         * thứ tự chữ. Với rank 1-7 thì text-sort trùng numeric-sort; ngay khi có
         * rank hai chữ số thì `'10' < '6'` và cả bảng xếp hạng lật ngược.
         */
        $results = viSimplified('xin chào');

        expect(array_search('你好', $results, true))
            ->toBeLessThan(array_search('新潮', $results, true));
    });

    it('GIỮ pinyin lên đầu khi truy vấn KHÔNG dấu', function (): void {
        // `ni hao` và `xue xi` (pinyin gõ tách) cũng khớp nghĩa tiếng Việt, nên
        // "có dấu cách + khớp được" là tín hiệu quá yếu. Dấu mới là tín hiệu:
        // người học gõ pinyin hầu như luôn gõ không dấu.
        expect(viSimplified('xin chao')[0])->toBe('新潮');
    });

    it('KHÔNG hạ pinyin cho pinyin gõ CÓ dấu thanh', function (): void {
        // `xuéxí` cũng "có dấu" theo nghĩa ký tự. Chốt chặn là vế thứ ba của
        // điều kiện hạ bậc: nghĩa tiếng Việt phải thật sự khớp cái gì đó. Không
        // có nó thì 学习 rơi khỏi vị trí 1 cho chính người gõ pinyin chuẩn.
        expect(viSimplified('xuéxí')[0])->toBe('学习');
    });
});

describe('âm Hán-Việt không được nuốt truy vấn CÓ DẤU', function (): void {
    it('không để âm chỉ va nhau SAU KHI bỏ dấu đè lên nghĩa đúng', function (): void {
        /*
         * Đây là ca `chó` thu nhỏ. Trên dữ liệu thật: `chó` khớp 23 dòng qua
         * prefix bỏ dấu `cho%` — 撑 (`chống`), 帚 (`chổi`), 肘 (`chỏ`). Không
         * dòng nào ĐỌC là `chó`. Rank 5 đứng trên rank 6, nên cả 23 dòng đó đẩy
         * 狗 — từ có nghĩa tiếng Việt đúng bằng `chó` — ra khỏi trang 1, mà màn
         * tìm kiếm không phân trang.
         *
         * Ở fixture: 桥 có nghĩa tiếng Việt TRỌN VẸN `cầu`; 狗 đọc là `cẩu`, bỏ
         * dấu thành `cau` và khớp CHÍNH XÁC `han_viet_plain` của truy vấn.
         *
         * Chốt bằng THỨ TỰ chứ không phải membership: `search_tsv` vốn đã bỏ dấu
         * âm Hán-Việt nên 狗 vẫn tới được qua full-text rank 6, và đó là hành vi
         * cũ không thuộc phạm vi phase này. Thứ phải đúng là 桥 đứng TRƯỚC.
         */
        $results = viSimplified('cầu', 'vi');

        expect($results[0])->toBe('桥')
            ->and(array_search('桥', $results, true))
            ->toBeLessThan(array_search('狗', $results, true));
    });

    it('vẫn khớp khi dấu KHỚP thật', function (): void {
        expect(viSimplified('ngân', 'vi'))->toContain('银');
    });

    it('truy vấn KHÔNG dấu đi đường cũ, không đổi một dòng nào', function (): void {
        // Người gõ không dấu vốn đã chấp nhận sự mơ hồ đó; siết vế có dấu lên họ
        // là lấy mất chính đường mà `hoc tap` dùng.
        expect(viSimplified('ngan', 'vi'))->toContain('银');
    });
});

describe('hồi quy — 7 nhánh cũ', function (): void {
    /*
     * Snapshot danh sách CÓ THỨ TỰ, không phải containment.
     *
     * `assertJsonPath('data.0.simplified')` và `toContain()` xanh dù nối bao
     * nhiêu nhiễu vào đuôi — chúng không phải regression guard. Màn tìm kiếm
     * KHÔNG có phân trang (`useSearchWords` không bao giờ truyền `page`), nên
     * trang 1 chính là toàn bộ thứ người dùng thấy.
     */
    it('giữ nguyên danh sách kết quả và total', function (string $query, array $words, int $total): void {
        // Chốt bằng chữ Hán chứ KHÔNG phải id: sequence của Postgres không
        // rollback theo transaction, nên id nhảy theo số test đã chạy trước đó
        // trong cùng file.
        $result = viSearch($query);

        expect($result->json('data.*.simplified'))->toBe($words)
            ->and($result->json('meta.total'))->toBe($total);
    })->with([
        ['学习', ['学习'], 1],
        ['xuexi', ['学习', '学'], 2],
        ['xuéxí', ['学习', '学'], 2],
        ['xuexy', ['学习', '学'], 2],
    ]);

    it('`student` KHÔNG đổi thứ tự — full-text tiếng Anh vẫn ở bậc mặc định', function (): void {
        /*
         * Chốt chặn trực tiếp cho `PRECISION_DEFAULT`.
         *
         * Đặt mặc định 0 thay vì 6 sẽ đẩy mọi kết quả full-text tiếng Anh lên
         * trên mọi tầng nghĩa Việt trong cùng rank 6 — lật ngược đúng thứ phase
         * này xây, mà không test nào khác nhìn thấy.
         */
        $result = viSearch('student');

        expect($result->json('data.*.simplified'))->toBe(['学生'])
            ->and($result->json('meta.total'))->toBe(1);
    });

    /*
     * `học tập` là ca nền DUY NHẤT mà nhánh nghĩa Việt cũng khớp, nên nó là ca
     * duy nhất đổi total. Tiêu chí là "tiền tố do rank ≤5 sinh ra không đổi thứ
     * tự", KHÔNG phải "total không đổi" — total không đổi là tiêu chí sai, nó
     * cấm luôn việc nhánh mới tìm thêm được cái gì.
     */
    it('giữ nguyên tiền tố rank ≤5 cho truy vấn khớp cả hai nhánh', function (): void {
        expect(array_slice(viSearch('học tập')->json('data.*.simplified'), 0, 2))->toBe(['学习', '学']);
        expect(array_slice(viSearch('hoc tap')->json('data.*.simplified'), 0, 3))->toBe(['学习', '学', '习']);
    });
});

describe('nghĩa tiếng Việt trong response', function (): void {
    it('trả nghĩa tiếng Việt kèm nghĩa tiếng Anh, không thay thế', function (): void {
        $word = collect(viSearch('你好')->json('data'))->firstWhere('simplified', '你好');

        expect($word['definitions_vi'])->toBe(['xin chào', 'chào'])
            ->and($word['definitions_en'])->not->toBeEmpty();
    });

    it('trả null — KHÔNG phải mảng rỗng — cho từ không có nghĩa Việt', function (): void {
        // `null` để FE ẩn HẲN phần nghĩa Việt. Mảng rỗng sẽ khiến nó hiện một
        // khung trống, đúng thứ mà quy ước `han_viet: null` đã tránh được.
        $word = collect(viSearch('沙发')->json('data'))->firstWhere('simplified', '沙发');

        expect($word['definitions_vi'])->toBeNull()
            ->and($word['definitions_en'])->not->toBeEmpty();
    });

    it('trang chi tiết cũng trả nghĩa tiếng Việt', function (): void {
        $id = DictionaryWord::where('simplified', '你好')->value('id');

        expect($this->actingAs($this->user, 'sanctum')
            ->getJson("/api/dictionary/words/{$id}")
            ->assertOk()
            ->json('data.definitions_vi'))->toBe(['xin chào', 'chào']);
    });
});

describe('lọc kho từ', function (): void {
    it('tìm được từ đã lưu bằng nghĩa tiếng Việt', function (): void {
        $word = DictionaryWord::where('simplified', '猫')->firstOrFail();
        UserWord::factory()->create(['user_id' => $this->user->id, 'word_id' => $word->id]);

        $result = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/vocabulary?'.http_build_query(['q' => 'con mèo']))
            ->assertOk()
            ->json('data');

        expect(collect($result)->pluck('word.simplified')->all())->toContain('猫');
    });

    it('KHÔNG khớp từ chỉ liên quan qua âm Hán-Việt bỏ dấu', function (): void {
        // 吃 lọt vào kết quả `con mèo` của cầu nối cũ vì âm Hán-Việt `cật` bỏ dấu
        // thành `cat`. Bộ lọc kho từ phải hiểu một chuỗi giống hệt màn tìm kiếm.
        $word = DictionaryWord::where('simplified', '吃')->firstOrFail();
        UserWord::factory()->create(['user_id' => $this->user->id, 'word_id' => $word->id]);

        $result = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/vocabulary?'.http_build_query(['q' => 'con mèo']))
            ->assertOk()
            ->json('data');

        expect($result)->toBeEmpty();
    });
});

describe('mode do người dùng chọn', function (): void {
    /*
     * Mode thay cho việc `QueryClassifier` đoán. Cùng một chuỗi `xin chào`:
     * `vi` phải ra 你好, `cn` phải ra 新潮 — không heuristic nào làm được điều đó
     * vì chuỗi này hợp lệ ở cả hai không gian.
     */
    it('mode=vi bỏ hẳn nhánh pinyin', function (): void {
        $results = viSimplified('xin chào', 'vi');

        expect($results)->toContain('你好')
            ->and($results)->not->toContain('新潮');
    });

    it('mode=cn bỏ hẳn nhánh nghĩa tiếng Việt', function (): void {
        $results = viSimplified('xin chào', 'cn');

        expect($results[0])->toBe('新潮')
            ->and($results)->not->toContain('你好');
    });

    it('mode=cn giữ pinyin chạy bình thường', function (): void {
        expect(viSimplified('xuexi', 'cn')[0])->toBe('学习');
    });

    it('full-text định nghĩa tiếng Anh chạy ở CẢ HAI mode', function (): void {
        expect(viSimplified('student', 'vi'))->toContain('学生')
            ->and(viSimplified('student', 'cn'))->toContain('学生');
    });

    it('chữ Hán thắng trước mode', function (): void {
        // CJK không mơ hồ nên mode không có gì để quyết. Dán 学习 lúc đang ở
        // `vi` thì vẫn phải ra 学习.
        expect(viSimplified('学习', 'vi')[0])->toBe('学习')
            ->and(viSimplified('学习', 'cn')[0])->toBe('学习');
    });

    it('KHÔNG phát hint khi mode=cn không ra gì', function (): void {
        expect(viSearch('zzzqqq', 'cn')->json('meta.hint'))->toBeNull();
    });

    it('KHÔNG phát hint cho truy vấn chữ Hán, kể cả ở mode=vi', function (): void {
        /*
         * `buildQuery()` đặt chữ Hán TRƯỚC mode, nên truy vấn CJK đã chạy
         * `hanBranches()` — độ phủ âm Hán-Việt không liên quan gì tới việc nó
         * không ra kết quả.
         *
         * Phát hint ở đây là dẫn vào ngõ cụt: FE khuyên "thử chuyển sang 中文",
         * mà theo đúng thiết kế thì chuyển sang cũng chạy y hệt nhánh đó.
         */
        expect(viSearch('鿃', 'vi')->json('meta.hint'))->toBeNull()
            ->and(viSearch('鿃', 'cn')->json('meta.hint'))->toBeNull()
            ->and(viSearch('鿃')->json('meta.hint'))->toBeNull();
    });

    it('mode lạ trả 422, không im lặng rơi về auto', function (): void {
        // `mode=vn` gõ nhầm mà vẫn trả 200 thì client không bao giờ biết mình sai.
        viSearch('học tập', 'vn')->assertStatus(422);
    });

    it('thiếu mode giữ nguyên đường auto', function (): void {
        // Đường auto không được sửa một dòng nào.
        expect(array_slice(viSimplified('hoc tap'), 0, 3))->toBe(['学习', '学', '习']);
    });
});
