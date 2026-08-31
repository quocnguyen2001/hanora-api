<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\User;
use App\Services\Dictionary\WordSearchService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);
    Artisan::call('han-viet:import', [
        '--unihan' => base_path('tests/Fixtures/unihan-sample.txt'),
        '--supplement' => base_path('tests/Fixtures/hanviet-supplement-sample.csv'),
    ]);

    $this->user = User::factory()->create();
});

function search(string $query, array $params = []): TestResponse
{
    return test()->actingAs(test()->user, 'sanctum')
        ->getJson('/api/dictionary/search?'.http_build_query(['q' => $query, ...$params]));
}

describe('auth — D8', function (): void {
    it('trả 401 khi không có token', function (): void {
        // Không có chế độ khách, kể cả cho tra từ điển.
        $this->getJson('/api/dictionary/search?q=学习')->assertUnauthorized();
    });

    it('trả 401 cho chi tiết từ khi không có token', function (): void {
        $id = DictionaryWord::where('simplified', '学习')->value('id');

        $this->getJson("/api/dictionary/words/{$id}")->assertUnauthorized();
    });
});

describe('xếp hạng', function (): void {
    it('đưa khớp chính xác chữ Hán lên đầu', function (): void {
        search('学习')->assertOk()->assertJsonPath('data.0.simplified', '学习');
    });

    it('tìm được bằng pinyin có dấu và không dấu', function (string $query): void {
        search($query)->assertOk()->assertJsonPath('data.0.simplified', '学习');
    })->with(['xuexi', 'xuéxí', 'xue2xi2', 'XUEXI']);

    it('tìm được bằng âm Hán-Việt có dấu và không dấu', function (string $query): void {
        $simplified = collect(search($query)->json('data'))->pluck('simplified');

        expect($simplified)->toContain('学习');
    })->with(['học tập', 'hoc tap']);

    it('tìm được bằng định nghĩa tiếng Anh', function (): void {
        $simplified = collect(search('student')->json('data'))->pluck('simplified');

        expect($simplified)->toContain('学生');
    });

    it('vẫn ra kết quả khi gõ sai nhờ trigram', function (): void {
        // `xuexy` sai một ký tự so với `xuexi`.
        expect(search('xuexy')->json('data'))->not->toBeEmpty();
    });

    it('xếp khớp chính xác trước prefix', function (): void {
        $data = search('学')->json('data');

        // 学 (chính xác, rank 1) phải đứng trước 学习/学生 (prefix, rank 2).
        expect($data[0]['simplified'])->toBe('学');
    });
});

describe('meta và phân trang', function (): void {
    it('trả meta đầy đủ', function (): void {
        search('学习')->assertOk()->assertJsonStructure([
            'data', 'meta' => ['page', 'per_page', 'total', 'hint'],
        ]);
    });

    it('trả hint hv_not_found khi truy vấn tiếng Việt không ra gì', function (): void {
        // Độ phủ Hán-Việt chưa 100%, nên "không thấy" có thể do dữ liệu chứ
        // không phải người dùng gõ sai. P7 dùng hint này để nói đúng chuyện đó.
        search('kính ngữ không tồn tại')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.hint', WordSearchService::HINT_HAN_VIET_NOT_FOUND);
    });

    it('không trả hint khi truy vấn pinyin không ra gì', function (): void {
        search('zzzqqq')->assertOk()->assertJsonPath('meta.hint', null);
    });

    it('không trả hint khi có kết quả', function (): void {
        search('học tập')->assertOk()->assertJsonPath('meta.hint', null);
    });
});

describe('validation — bound cụ thể', function (): void {
    it('bắt buộc có q', function (): void {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/dictionary/search')
            ->assertStatus(422)->assertJsonValidationErrors('q');
    });

    it('từ chối q dài quá 64 ký tự', function (): void {
        // Không chặn thì `?q=<8KB rác>` ép chạy trigram trên 120k dòng — một
        // request rẻ tiền khóa được cả database.
        search(str_repeat('z', 65))->assertStatus(422)->assertJsonValidationErrors('q');
    });

    it('từ chối page ngoài khoảng', function (): void {
        search('学习', ['page' => 0])->assertStatus(422)->assertJsonValidationErrors('page');
        search('学习', ['page' => 501])->assertStatus(422)->assertJsonValidationErrors('page');
    });

    it('coi q toàn khoảng trắng là rỗng', function (): void {
        search('   ')->assertStatus(422)->assertJsonValidationErrors('q');
    });

    it('không để ký tự wildcard của LIKE lọt vào truy vấn', function (): void {
        // `%` phải là ký tự thường. Nếu không thì `?q=%` quét sạch bảng.
        expect(search('%')->json('data'))->toBeEmpty();
    });
});

describe('response không chứa dữ liệu theo user — red team C2', function (): void {
    it('không có saved hay user_word_id trong kết quả tìm kiếm', function (): void {
        $first = search('学习')->json('data.0');

        expect(array_keys($first))->toBe([
            'id', 'simplified', 'traditional', 'pinyin', 'han_viet',
            'definitions_en', 'definitions_vi', 'hsk_level',
        ]);
    });

    it('đặt Cache-Control public vì response không theo user', function (): void {
        /*
         * Bất biến ở ĐÂY là `public`: response không mang trường nào theo user
         * nên chia sẻ được ở cache dùng chung.
         *
         * KHÔNG khoá con số TTL. Độ dài cache đi theo mức chung kết của câu trả
         * lời (`sql` ngắn, `ai` dài) và đã có test riêng ở `SearchAiLayerTest`;
         * khoá lại con số ở đây chỉ tạo thêm một chỗ phải sửa khi chỉnh TTL, cho
         * một bất biến mà bài test này không nói về.
         */
        $header = search('学习')->assertOk()->headers->get('Cache-Control');

        expect($header)->toContain('public')->not->toContain('no-store');
    });
});
