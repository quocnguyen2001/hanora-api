<?php

declare(strict_types=1);

use App\Models\ViEnLexiconEntry;
use App\Services\Dictionary\VietnameseQueryBridge;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

function importLexicon(array $options = []): int
{
    return Artisan::call('vi-lexicon:import', [
        '--path' => base_path('tests/Fixtures/vnedict-sample.txt'),
        '--skip-checksum' => true,
        ...$options,
    ]);
}

function senses(string $term): array
{
    return ViEnLexiconEntry::where('term', $term)->value('senses') ?? [];
}

describe('parse', function (): void {
    beforeEach(fn () => importLexicon());

    it('import được các mục đúng định dạng', function (): void {
        expect(senses('mèo'))->toBe(['cat'])
            ->and(senses('máy tính'))->toBe(['calculator', 'computer'])
            ->and(senses('cảm ơn'))->toBe(['to thank', 'thank you']);
    });

    it('cứu được mục có dấu hai chấm dính', function (string $term, array $expected): void {
        // Nguồn thật có 13 dòng như thế. Bỏ chúng chỉ vì thiếu một dấu cách là
        // mất dữ liệu vô cớ.
        expect(senses($term))->toBe($expected);
    })->with([
        ['kền kền', ['vulture']],
        ['hậu nghiệm', ['a posteriori']],
    ]);

    it('bỏ dòng không có dấu hai chấm', function (): void {
        // Câu ví dụ lọt vào nguồn, phân tách bằng dấu phẩy. Không cứu được.
        expect(ViEnLexiconEntry::where('term', 'like', 'con sông%')->exists())->toBeFalse();
    });

    it('bỏ chú thích trong ngoặc tròn và vuông', function (): void {
        // Ngoặc VUÔNG là dạng thật trong nguồn. Sót nó nghĩa là nhồi cả
        // "cl for animals and other small objects" vào tsquery.
        expect(senses('con'))->toBe(['child'])
            ->and(senses('a la hán'))->toBe(['arhant', 'lohan']);
    });

    it('GIỮ nguyên nghĩa dài — lọc là việc của query time', function (): void {
        expect(senses('đương nhiên'))->toBe([
            'used to indicate that something happens as a matter of course',
        ]);
    });

    it('sinh term_plain bỏ dấu, giữ term có dấu', function (): void {
        $entry = ViEnLexiconEntry::where('term', 'máy tính')->firstOrFail();

        expect($entry->term)->toBe('máy tính')
            ->and($entry->term_plain)->toBe('may tinh');
    });

    it('giữ các đồng âm khác dấu thành dòng riêng', function (): void {
        // `term` là khóa tự nhiên, KHÔNG phải `term_plain`. Gộp chúng sẽ trộn
        // "of / to saw / crab" vào một chỗ và không tách lại được.
        //
        // Không assert thứ tự: đó là collation của Postgres, không phải bất biến
        // của phase này. Thứ cần đúng là BA dòng riêng, mỗi dòng giữ nghĩa của nó.
        $rows = ViEnLexiconEntry::where('term_plain', 'cua')->pluck('senses', 'term');

        expect($rows)->toHaveCount(4)
            ->and($rows['của'])->toBe(['of', 'belonging to'])
            ->and($rows['cưa'])->toBe(['to saw', 'amputate'])
            ->and($rows['cửa'])->toBe(['door', 'entrance']);
    });
});

describe('khử trùng và idempotent', function (): void {
    it('gộp nghĩa của term trùng thay vì crash', function (): void {
        // Postgres từ chối ON CONFLICT DO UPDATE chạm cùng một dòng hai lần.
        // Nguồn thật có 15 term như vậy; thiếu pre-pass là import chết giữa
        // chừng và để lại bảng dở dang.
        expect(importLexicon())->toBe(0)
            ->and(senses('bà nội'))->toBe(['paternal grandmother', "grandmother on father's side"]);
    });

    it('chạy hai lần không nhân đôi', function (): void {
        importLexicon();
        $first = ViEnLexiconEntry::count();

        importLexicon();

        expect(ViEnLexiconEntry::count())->toBe($first);
    });

    it('làm mới version cache sau mỗi lần import', function (): void {
        // Version derive từ `MAX(updated_at)` của chính bảng lexicon, không phải
        // một bộ đếm trong cache — bộ đếm sống trong keyspace `allkeys-lru` mà
        // nó phục vụ, bị đuổi thì tụt về 0 rồi đếm lại từ 1 và phục vụ tiếp kết
        // quả sinh từ lexicon CŨ.
        importLexicon();
        $bridge = app(VietnameseQueryBridge::class);
        $bridge->resolve('mèo');

        expect(Cache::get('vi_lexicon:version'))->not->toBeNull();

        importLexicon();

        // Import xóa key, nên lần tra sau tính lại từ database.
        expect(Cache::get('vi_lexicon:version'))->toBeNull();
    });
});

describe('rác trong nguồn', function (): void {
    beforeEach(fn () => importLexicon());

    it('cắt gloss khi nguồn dính hai mục vào nhau', function (): void {
        // `ong ruồi : honey : bee` — dấu hai chấm thứ hai là dấu hiệu tin cậy của
        // dòng bị dính. Không cắt thì chuỗi "honey : bee" đi thẳng vào tsquery.
        expect(senses('ong ruồi'))->toBe(['honey']);
    });

    it('bỏ mục có nghĩa chỉ gồm chú thích trong ngoặc', function (): void {
        // `Bình Định : (province name)` — nhãn phân loại, không phải bản dịch.
        // Làm từ khóa tìm kiếm thì vô dụng.
        expect(ViEnLexiconEntry::where('term', 'bình định')->exists())->toBeFalse();
    });

    it('bỏ nghĩa còn sót dấu hai chấm sau khi gỡ ngoặc', function (): void {
        // `két : (1) screech, ... grinding (sound): (2) safe, case; (3) teal`
        // Dấu hai chấm dính vào `)` nên bước cắt phía trên không thấy; nó chỉ lộ
        // ra sau khi ngoặc bị gỡ, dán `grinding` với `safe` thành một nghĩa.
        expect(senses('két'))->toBe(['screech', 'gnashing', 'case', 'teal']);
    });

    it('hạ chữ thường và gộp cặp đụng nhau vì hạ chữ', function (): void {
        // 1.237 mục trong nguồn có chữ hoa. Giữ nguyên thì gõ `ba lê` không bao
        // giờ khớp `term` của `Ba Lê`. Hạ chữ làm 70 cặp đụng nhau — chúng đi qua
        // đúng đường gộp nghĩa của khóa trùng và giữ được cả hai nghĩa.
        expect(senses('ba lê'))->toBe(['paris', 'ballet'])
            ->and(ViEnLexiconEntry::where('term', 'Ba Lê')->exists())->toBeFalse();
    });
});

describe('toàn vẹn nguồn', function (): void {
    it('DỪNG khi SHA-256 không khớp, không ghi dòng nào', function (): void {
        // Nguồn đi qua HTTP thuần và host không phục vụ được HTTPS. Ngưỡng "đủ
        // số dòng" không phát hiện được file bị thay hoàn toàn.
        $exit = Artisan::call('vi-lexicon:import', [
            '--path' => base_path('tests/Fixtures/vnedict-sample.txt'),
        ]);

        expect($exit)->toBe(1)
            ->and(ViEnLexiconEntry::count())->toBe(0);
    });

    it('báo lỗi khi không đọc được file', function (): void {
        expect(Artisan::call('vi-lexicon:import', ['--path' => '/khong/ton/tai.txt']))->toBe(1);
    });

    it('coi 0 mục là THẤT BẠI, không phải bảng rỗng hợp lệ', function (): void {
        // `--skip-checksum` cộng một file rỗng sẽ in "đã upsert 0 mục" rồi trả
        // exit 0 nếu không có chốt này, và script deploy đi tiếp như thường.
        $empty = tempnam(sys_get_temp_dir(), 'vnedict');
        file_put_contents($empty, "# rỗng\n");

        expect(importLexicon(['--path' => $empty]))->toBe(1);

        unlink($empty);
    });
});

describe('gate sẵn sàng', function (): void {
    it('FAIL khi bảng rỗng', function (): void {
        expect(Artisan::call('vi-lexicon:status'))->toBe(1);
    });

    it('PASS khi đủ ngưỡng', function (): void {
        importLexicon();

        expect(Artisan::call('vi-lexicon:status', ['--threshold' => 5]))->toBe(0);
    });

    it('health trả số mục để phát hiện deploy thiếu import từ xa', function (): void {
        importLexicon();

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.vi_lexicon', ViEnLexiconEntry::count());
    });
});
