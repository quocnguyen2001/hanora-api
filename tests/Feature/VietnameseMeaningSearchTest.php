<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\User;
use App\Models\UserWord;
use App\Services\Dictionary\VietnameseQueryBridge;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

/**
 * Nhánh tìm theo nghĩa tiếng Việt (rank 6) + bộ lọc kho từ.
 *
 * Nạp fixture RIÊNG chồng lên fixture chung. Không sửa `cedict-sample.u8`: bảy
 * test file nạp nó, `DictionaryImportTest` chốt số dòng, `HanVietImportTest`
 * chốt exit code của gate độ phủ, và `ReviewTest` bốc distractor ngẫu nhiên từ
 * cùng bảng — thêm ba từ vào đó là biến ba test thành FLAKY chứ không phải đỏ
 * dứt khoát.
 */
beforeEach(function (): void {
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-vi-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);
    Artisan::call('han-viet:import', [
        '--unihan' => base_path('tests/Fixtures/unihan-sample.txt'),
        '--supplement' => base_path('tests/Fixtures/hanviet-supplement-sample.csv'),
    ]);
    Artisan::call('vi-lexicon:import', [
        '--path' => base_path('tests/Fixtures/vnedict-sample.txt'),
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

describe('tìm theo nghĩa tiếng Việt', function (): void {
    it('tìm được bằng nghĩa thuần Việt', function (string $query, string $expected): void {
        expect(viSimplified($query))->toContain($expected);
    })->with([
        ['con mèo', '猫'],
        ['cảm ơn', '谢谢'],
        ['máy tính', '电脑'],
        ['may tinh', '电脑'],
        // Gõ KHÔNG DẤU sau loại từ — kiểu gõ phổ biến nhất. `meo` là một mục
        // VNEDICT thật ("moldy"), nên nếu khớp chính xác được phép thắng thì
        // `mèo → cat` không bao giờ được xét và kết quả là 霉 chứ không phải 猫.
        // Đo được 557 nhóm `term_plain` bị che kiểu này.
        ['con meo', '猫'],
    ]);

    it('bỏ loại từ đứng đầu để lấy trung tâm ngữ', function (): void {
        // Không có bước này thì `con mèo` resolve thành `con` ("child, you, i")
        // vì cả hai đều là span một token và `con` đứng trước.
        expect(viSimplified('con mèo'))->toContain('猫');
    });

    it('bắt được trung tâm ngữ khi định ngữ đứng SAU', function (): void {
        // `con mèo đen`: bỏ dần token đầu sẽ trượt qua `mèo` rồi hạ cánh xuống
        // `đen` và trả về mọi từ nghĩa "black". Trả sai tệ hơn không trả gì.
        expect(viSimplified('con mèo đen'))->toContain('猫');
    });

    it('KHÔNG khớp qua âm Hán-Việt bỏ dấu trùng từ khóa tiếng Anh', function (): void {
        // `search_tsv` trộn âm Hán-Việt với định nghĩa tiếng Anh. Từ khóa `cat`
        // khớp 吃 vì âm Hán-Việt của nó là `cật`, bỏ dấu thành `cat`, và 吃 có
        // frequency_rank tốt hơn 猫 nên sẽ đứng TRƯỚC nếu không recheck.
        $results = viSimplified('con mèo');

        expect($results)->toContain('猫')
            ->and($results)->not->toContain('吃');
    });
});

describe('hư từ đứng đầu ngữ', function (): void {
    it('xét trung tâm ngữ trước hư từ', function (): void {
        // VNEDICT không có mục `xin chào`; nó có `chào : hello` và
        // `xin : to ask for, request, beg`. Span cùng độ dài duyệt trái sang phải
        // thì `xin` thắng và từ khóa thành "ask, request, beg" — người dùng chào
        // hỏi mà nhận về từ nghĩa "xin xỏ".
        expect(viSimplified('xin chào'))->toContain('你好');
    });

    it('KHÔNG cắt hư từ — cụm hai tiếng có thật vẫn thắng', function (): void {
        // `xin lỗi` LÀ một mục VNEDICT. Bản trước cắt `xin` ngay từ đầu, tức là
        // vĩnh viễn không tra được nó — chỉ còn `lỗi` ("mistake, fault").
        expect(app(VietnameseQueryBridge::class)->resolve('xin lỗi'))
            ->toContain('apologize');
    });

    it('hạ pinyin xuống khi truy vấn CÓ DẤU và cầu nối tra được', function (): void {
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
        // `ni hao` và `xue xi` (pinyin gõ tách) cũng tra được ra nghĩa tiếng Việt,
        // nên "có dấu cách + tra được" là tín hiệu quá yếu. Dấu mới là tín hiệu:
        // người học gõ pinyin hầu như luôn gõ không dấu.
        expect(viSimplified('xin chao')[0])->toBe('新潮');
    });
});

describe('không cầu nối được', function (): void {
    it('bỏ qua truy vấn quá ngắn', function (string $query): void {
        // `de`, `va` là hư từ tiếng Việt bỏ dấu. Ngưỡng độ dài tối thiểu chặn
        // chúng trước khi tra từ điển, nên không cần danh sách hư từ riêng.
        expect(app(VietnameseQueryBridge::class)->resolve($query))->toBe([]);
    })->with(['de', 'va', 'ma']);

    it('bỏ qua dạng không dấu khớp quá nhiều đồng âm', function (): void {
        // Trên dữ liệu thật `cua` khớp cúa/cưa/của/cứa/cửa/cựa — gộp nghĩa của
        // cả sáu cho ra sáu từ khóa không liên quan gì nhau. VNEDICT KHÔNG có
        // mục `cua : crab`, chỉ có `cua bấy : soft-shelled crab`.
        expect(app(VietnameseQueryBridge::class)->resolve('cua'))->toBe([]);
    });

    it('không cầu nối được thì không đổi gì', function (string $query): void {
        expect(app(VietnameseQueryBridge::class)->resolve($query))->toBe([]);
    })->with(['xuexi', 'xuexy', 'student']);
});

describe('hồi quy — 7 nhánh cũ', function (): void {
    /*
     * Snapshot danh sách id CÓ THỨ TỰ, không phải containment.
     *
     * `assertJsonPath('data.0.simplified')` và `toContain()` xanh dù nối bao
     * nhiêu nhiễu vào đuôi — chúng không phải regression guard. Màn tìm kiếm
     * KHÔNG có phân trang (`useSearchWords` không bao giờ truyền `page`), nên
     * trang 1 chính là toàn bộ thứ người dùng thấy: vị trí 3-20 bị lấp là
     * regression thật.
     */
    it('giữ nguyên danh sách kết quả và total', function (string $query, array $words, int $total): void {
        // Chốt bằng chữ Hán chứ KHÔNG phải id: sequence của Postgres không
        // rollback theo transaction, nên id nhảy theo số test đã chạy trước đó
        // trong cùng file. Chốt id là chốt một con số không ổn định.
        $result = viSearch($query);

        expect($result->json('data.*.simplified'))->toBe($words)
            ->and($result->json('meta.total'))->toBe($total);
    })->with([
        ['学习', ['学习'], 1],
        ['xuexi', ['学习', '学'], 2],
        ['xuéxí', ['学习', '学'], 2],
        ['xuexy', ['学习', '学'], 2],
        ['hoc tap', ['学习', '学', '习'], 3],
        ['student', ['学生'], 1],
    ]);

    /*
     * `học tập` là ca DUY NHẤT trong bảy truy vấn nền mà cầu nối resolve được,
     * nên nó là ca duy nhất đổi. Đo trên 123.646 dòng thật, trước/sau Phase 2:
     *
     *   学习 · xuexi · xuéxí · xuexy · student   → id list và total y hệt
     *   học tập · hoc tap                        → total 9 → 426
     *
     * Ba vị trí đầu (rank 5, khớp âm Hán-Việt chính xác) KHÔNG đổi: 学习,
     * 学习强国, 学习时报. Phần đuôi trước đây trống, giờ là từ liên quan theo
     * nghĩa (了解, 学, 研究, 训练, 练习) lẫn nhiễu đa nghĩa (错过 "miss the
     * train", 开车 "drive a train") — cái giá của việc bắc cầu qua gloss tiếng
     * Anh, và `MAX_TERMS` trong bridge là núm siết nó.
     *
     * Vì thế tiêu chí là "tiền tố do rank ≤5 sinh ra không đổi thứ tự", KHÔNG
     * phải "total không đổi". Total không đổi là tiêu chí sai: nó cấm luôn việc
     * nhánh mới tìm thêm được cái gì.
     */
    it('giữ nguyên tiền tố rank ≤5 cho truy vấn mà cầu nối resolve được', function (): void {
        expect(array_slice(viSearch('học tập')->json('data.*.simplified'), 0, 2))->toBe(['学习', '学']);
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

    it('KHÔNG khớp chuỗi con — `cat` không được kéo về `education`', function (): void {
        // Năm điều kiện ilike cũ khớp chuỗi người dùng TỰ GÕ nên false positive
        // tự giải thích. Từ khóa do cầu nối sinh ra thì không.
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

    it('mode=cn bỏ hẳn cầu nối nghĩa tiếng Việt', function (): void {
        $results = viSimplified('xin chào', 'cn');

        expect($results[0])->toBe('新潮')
            ->and($results)->not->toContain('你好');
    });

    it('mode=vi giữ 猫 lên đầu, không có rác trigram', function (): void {
        expect(viSimplified('con mèo', 'vi')[0])->toBe('猫');
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

    it('phát hint khi mode=vi không ra gì', function (): void {
        expect(viSearch('zzzqqq', 'vi')->json('meta.hint'))->toBe('hv_not_found');
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
        // Đường auto không được sửa một dòng nào. Đây là cùng snapshot với bộ
        // test hồi quy phía trên, lặp lại ở đây để nếu ai đó đổi `buildQuery()`
        // thì thấy ngay là đã chạm vào đường cũ.
        expect(viSimplified('hoc tap'))->toBe(['学习', '学', '习']);
    });
});
