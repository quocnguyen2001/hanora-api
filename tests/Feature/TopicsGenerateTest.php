<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Services\Topic\TopicGenerationOutcome;
use App\Services\Topic\TopicGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * `topics:generate` là lệnh DUY NHẤT trong tính năng có gọi AI. Không test nào
 * ở đây được phép chạm Gemini thật — `Http::fake()` là mặc định, và mấy test
 * cuối khẳng định điều đó bằng cách đếm request.
 */
beforeEach(function (): void {
    config(['services.gemini.key' => 'test-key']);
    Sleep::fake();

    /*
     * Thư mục TẠM, không phải `database/data/topics`.
     *
     * Bài học từ chính lần chạy thật đầu tiên: hai test khẳng định "file không
     * tồn tại" đã đỏ ngay khi bộ 16 file thật được sinh ra. Test ghi vào thư mục
     * dữ liệu đã commit là test phụ thuộc vào trạng thái của repo.
     */
    $this->dir = storage_path('framework/testing/topics-gen-'.uniqid());
    File::ensureDirectoryExists($this->dir);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/**
 * @param  list<array{zh: string, pinyin: string, vi: string}>  $items
 */
function topicResponse(array $items): array
{
    return [
        'status' => 'completed',
        'usage' => ['total_input_tokens' => 100, 'total_output_tokens' => 200],
        'steps' => [
            ['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)],
            ]],
        ],
        'model' => 'test',
    ];
}

function seedWord(string $zh, string $pinyin, array $vi, ?int $rank = 100): DictionaryWord
{
    return DictionaryWord::factory()->create([
        'simplified' => $zh, 'traditional' => $zh, 'pinyin_numbered' => $pinyin,
        'definitions_vi' => $vi, 'han_viet' => 'âm', 'frequency_rank' => $rank,
        'han_viet_status' => DictionaryWord::STATUS_OK,
    ]);
}

it('429 KHÔNG được giả dạng thành "chủ đề đã cạn"', function (): void {
    // Đây là finding nghiêm trọng nhất của red team về lệnh này. Một vòng bị
    // chặn trả 0 từ; nếu 429 rơi chung nhánh với "cạn" thì generator kết luận
    // model đã hết vốn từ, ghi ra một file CỤT trông hợp lệ, và người rà commit
    // nó vào git mà không có cách nào nhận ra.
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => '2'])]);

    $result = app(TopicGenerator::class)->generate('tinh-yeu', 'tình yêu');

    expect($result->outcome)->toBe(TopicGenerationOutcome::Throttled)
        ->and($result->isWritable())->toBeFalse()
        ->and($result->failureReason)->toBe('rate_limited');
});

it('không ghi file và exit khác 0 khi bị 429', function (): void {
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => '1'])]);

    $this->artisan('topics:generate', ['--topic' => 'tinh-yeu', '--path' => $this->dir])->assertFailed();

    expect(File::exists("{$this->dir}/tinh-yeu.json"))->toBeFalse();
});

it('lỗi Gemini khác 429 cũng không ghi file', function (): void {
    Http::fake(['*' => Http::response('boom', 500)]);

    $result = app(TopicGenerator::class)->generate('tinh-yeu', 'tình yêu');

    expect($result->outcome)->toBe(TopicGenerationOutcome::Failed)
        ->and($result->isWritable())->toBeFalse();
});

it('dừng khi một vòng trùng quá ngưỡng với vòng trước', function (): void {
    seedWord('爱', 'ai4', ['yêu; thích'], 10);
    seedWord('喜欢', 'xi3 huan5', ['thích'], 20);

    $round = [
        ['zh' => '爱', 'pinyin' => 'ai4', 'vi' => 'yêu'],
        ['zh' => '喜欢', 'pinyin' => 'xi3 huan5', 'vi' => 'thích'],
    ];

    // Vòng 2 lặp y nguyên vòng 1 → tỉ lệ trùng 100% > 35% → dừng ở vòng 2.
    Http::fake(['*' => Http::sequence()
        ->push(topicResponse($round))
        ->push(topicResponse($round))
        ->push(topicResponse($round)),
    ]);

    $result = app(TopicGenerator::class)->generate('tinh-yeu', 'tình yêu');

    expect($result->outcome)->toBe(TopicGenerationOutcome::Exhausted)
        ->and($result->count())->toBe(2)
        ->and($result->roundStats)->toHaveCount(2)
        ->and($result->rejections['duplicate'] ?? 0)->toBe(2);
});

/**
 * D10 — đây là ca đắt nhất của cả phase.
 *
 * Luật cũ (`is_priority` → `frequency_rank` → `id`) chọn theo thứ tự dòng
 * CC-CEDICT vì `frequency_rank` gán theo HÌNH CHỮ nên luôn hoà. Kết quả đo được
 * trên DB thật: `东西` ra "đông và tây" thay vì "đồ vật".
 */
it('chọn cách đọc theo pinyin model trả về, không theo id nhỏ nhất', function (): void {
    // Cùng `frequency_rank` — đúng như dữ liệu thật, hai bậc đầu của luật cũ hoà.
    $dongTay = seedWord('东西', 'dong1 xi1', ['đông và tây'], 500);
    $doVat = seedWord('东西', 'dong1 xi5', ['đồ vật; thứ'], 500);

    expect($dongTay->id)->toBeLessThan($doVat->id);

    Http::fake(['*' => Http::sequence()
        ->push(topicResponse([['zh' => '东西', 'pinyin' => 'dong1 xi5', 'vi' => 'đồ vật']]))
        ->push(topicResponse([['zh' => '东西', 'pinyin' => 'dong1 xi5', 'vi' => 'đồ vật']])),
    ]);

    $result = app(TopicGenerator::class)->generate('nha-cua', 'nhà cửa');

    expect($result->words[0]['pinyin'])->toBe('dong1 xi5')
        ->and($result->words[0]['vi'])->toBe('đồ vật; thứ')
        ->and($result->words[0]['rejected'][0]['vi'])->toBe('đông và tây');
});

it('chọn cách đọc bằng NGHĨA khi model không cho pinyin khớp', function (): void {
    seedWord('东西', 'dong1 xi1', ['đông và tây'], 500);
    seedWord('东西', 'dong1 xi5', ['đồ vật; thứ'], 500);

    // Pinyin sai/thiếu → phải rơi về chấm điểm bằng nghĩa model đưa.
    Http::fake(['*' => Http::sequence()
        ->push(topicResponse([['zh' => '东西', 'pinyin' => '', 'vi' => 'đồ vật']]))
        ->push(topicResponse([['zh' => '东西', 'pinyin' => '', 'vi' => 'đồ vật']])),
    ]);

    $result = app(TopicGenerator::class)->generate('nha-cua', 'nhà cửa');

    expect($result->words)->toHaveCount(1)
        ->and($result->words[0]['pinyin'])->toBe('dong1 xi5');
});

it('đánh dấu ambiguous thay vì đoán bừa khi không phân giải được', function (): void {
    seedWord('行', 'hang2', ['hàng; dãy'], 300);
    seedWord('行', 'xing2', ['đi; được'], 300);

    // Không pinyin, và nghĩa model đưa không dính dáng ứng viên nào.
    Http::fake(['*' => Http::sequence()
        ->push(topicResponse([['zh' => '行', 'pinyin' => '', 'vi' => 'xyz không liên quan']]))
        ->push(topicResponse([['zh' => '行', 'pinyin' => '', 'vi' => 'xyz không liên quan']])),
    ]);

    $result = app(TopicGenerator::class)->generate('du-lich', 'du lịch');

    expect($result->words)->toBeEmpty()
        ->and($result->rejections['ambiguous'] ?? 0)->toBeGreaterThan(0);
});

it('đếm riêng từng lý do loại thay vì gộp làm một', function (): void {
    seedWord('爱', 'ai4', ['yêu; thích'], 10);
    seedWord('龘', 'da2', ['rồng bay'], 900)->update([
        'han_viet' => null, 'han_viet_status' => DictionaryWord::STATUS_MISSING,
    ]);
    seedWord('碰', 'peng4', ['biến thể của 碰[peng4]'], 800);

    $items = [
        ['zh' => '爱', 'pinyin' => 'ai4', 'vi' => 'yêu'],
        ['zh' => '龘', 'pinyin' => 'da2', 'vi' => 'rồng'],
        ['zh' => '碰', 'pinyin' => 'peng4', 'vi' => 'chạm'],
        ['zh' => '不存在的字', 'pinyin' => 'bu4', 'vi' => 'không có'],
    ];

    Http::fake(['*' => Http::sequence()->push(topicResponse($items))->push(topicResponse($items))]);

    $result = app(TopicGenerator::class)->generate('tinh-yeu', 'tình yêu');

    // Bốn nguyên nhân khác nhau đòi bốn hành động khác nhau; gộp lại thì bảng
    // tổng kết chỉ nói "mất 3 từ" mà không nói vì sao.
    expect($result->rejections['missing_han_viet'] ?? 0)->toBe(1)
        ->and($result->rejections['dirty_gloss'] ?? 0)->toBe(1)
        ->and($result->rejections['not_found'] ?? 0)->toBe(1)
        ->and($result->count())->toBe(1);
});

it('xếp rank theo tần suất, thông dụng nhất trước', function (): void {
    seedWord('爱', 'ai4', ['yêu'], 10);
    seedWord('凝聚', 'ning2 ju4', ['ngưng tụ'], 9000);
    seedWord('喜欢', 'xi3 huan5', ['thích'], 50);

    $items = [
        ['zh' => '凝聚', 'pinyin' => 'ning2 ju4', 'vi' => 'ngưng tụ'],
        ['zh' => '爱', 'pinyin' => 'ai4', 'vi' => 'yêu'],
        ['zh' => '喜欢', 'pinyin' => 'xi3 huan5', 'vi' => 'thích'],
    ];

    Http::fake(['*' => Http::sequence()->push(topicResponse($items))->push(topicResponse($items))]);

    $result = app(TopicGenerator::class)->generate('tinh-yeu', 'tình yêu');

    // Màn học bốc trong LÁT CẮT đầu, nên sai thứ tự ở đây là dạy `凝聚` trước `爱`.
    expect(array_column($result->words, 'zh'))->toBe(['爱', '喜欢', '凝聚'])
        ->and(array_column($result->words, 'rank'))->toBe([1, 2, 3]);
});

it('--pretend không ghi file nào', function (): void {
    seedWord('爱', 'ai4', ['yêu'], 10);

    $items = [['zh' => '爱', 'pinyin' => 'ai4', 'vi' => 'yêu']];
    Http::fake(['*' => Http::sequence()->push(topicResponse($items))->push(topicResponse($items))]);

    $this->artisan('topics:generate', ['--topic' => 'tinh-yeu', '--pretend' => true, '--path' => $this->dir])->assertSuccessful();

    expect(File::exists("{$this->dir}/tinh-yeu.json"))->toBeFalse();
});

it('từ chối slug không có trong TopicCatalog mà không gọi Gemini', function (): void {
    Http::fake();

    $this->artisan('topics:generate', ['--topic' => 'khong-ton-tai'])->assertFailed();

    Http::assertNothingSent();
});

/**
 * Hồi quy cho lỗi tìm được khi RÀ đầu ra thật của 16 chủ đề.
 *
 * `NumberedPinyin` so khớp bỏ qua hoa/thường (đúng, vì CC-CEDICT viết hoa danh
 * từ riêng), nên `Mei3` và `mei3` khớp cùng một chuỗi. Bản đầu lấy `first()`
 * trong nhóm khớp đó — tức là quay về đúng luật "id nhỏ nhất" mà D10 loại bỏ.
 */
it('không để danh từ riêng thắng chỉ vì viết hoa pinyin', function (): void {
    $chauMy = seedWord('美', 'Mei3', ['(hình thái kết hợp) Châu Mỹ'], 200);
    $dep = seedWord('美', 'mei3', ['đẹp'], 200);

    expect($chauMy->id)->toBeLessThan($dep->id);

    $items = [['zh' => '美', 'pinyin' => 'mei3', 'vi' => 'đẹp']];
    Http::fake(['*' => Http::sequence()->push(topicResponse($items))->push(topicResponse($items))]);

    $result = app(TopicGenerator::class)->generate('tinh-yeu', 'tình yêu');

    expect($result->words[0]['vi'])->toBe('đẹp')
        ->and($result->words[0]['pinyin'])->toBe('mei3');
});

it('vẫn chọn được danh từ riêng khi đó là ứng viên duy nhất', function (): void {
    // `蓝牙 Lan2 ya2` = Bluetooth: viết hoa nhưng ĐÚNG, và không có cách đọc nào
    // khác. Bản vá không được loại bỏ nhóm này.
    seedWord('蓝牙', 'Lan2 ya2', ['Bluetooth'], 400);

    $items = [['zh' => '蓝牙', 'pinyin' => 'lan2 ya2', 'vi' => 'Bluetooth']];
    Http::fake(['*' => Http::sequence()->push(topicResponse($items))->push(topicResponse($items))]);

    $result = app(TopicGenerator::class)->generate('cong-nghe', 'công nghệ');

    expect($result->words)->toHaveCount(1)
        ->and($result->words[0]['vi'])->toBe('Bluetooth');
});
