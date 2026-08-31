<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\Topic;
use App\Services\Topic\TopicPrompt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * `topics:import` là lệnh GHI chạy trong runbook deploy production. Mọi test ở
 * đây khoá một cách nó có thể phá dữ liệu — đó là lý do file này tồn tại, không
 * phải để kiểm đường đi thuận lợi.
 */
beforeEach(function (): void {
    $this->dir = storage_path('framework/testing/topics-'.uniqid());
    File::ensureDirectoryExists($this->dir);

    // `topics:import` có kiểm tra tiên quyết: từ điển phải đã có nghĩa Việt và
    // âm Hán-Việt. Một từ đủ điều kiện là đủ để qua cửa đó.
    $this->word = DictionaryWord::factory()->create([
        'simplified' => '爱', 'traditional' => '愛',
        'pinyin_numbered' => 'ai4', 'definitions_vi' => ['yêu; thích'],
        'han_viet' => 'ái', 'han_viet_status' => DictionaryWord::STATUS_OK,
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

function writeTopicFile(string $dir, string $slug, array $words, ?int $promptVersion = null): string
{
    $path = "{$dir}/{$slug}.json";

    file_put_contents($path, json_encode([
        'slug' => $slug,
        'prompt_version' => $promptVersion ?? TopicPrompt::VERSION,
        'model' => 'test',
        'generated_at' => now()->toIso8601String(),
        'words' => $words,
    ], JSON_UNESCAPED_UNICODE));

    return $path;
}

function entry(DictionaryWord $word, int $rank = 1, int $batch = 1): array
{
    return ['zh' => $word->simplified, 'pinyin' => $word->pinyin_numbered, 'rank' => $rank, 'batch' => $batch];
}

it('nạp được và chạy lại hai lần cho ra đúng cùng dữ liệu', function (): void {
    writeTopicFile($this->dir, 'tinh-yeu', [entry($this->word)]);

    $this->artisan('topics:import', ['--path' => $this->dir])->assertSuccessful();

    // CẢ dòng, không chỉ ba cột: `updateOrInsert` ghi đè `created_at` mỗi lần
    // chạy, và một phép chiếu hẹp sẽ không bao giờ thấy điều đó.
    $first = DB::table('topic_words')->orderBy('id')->get()->toArray();

    $this->travel(5)->seconds();

    $this->artisan('topics:import', ['--path' => $this->dir])->assertSuccessful();

    $second = DB::table('topic_words')->orderBy('id')->get()->toArray();

    expect($second)->toEqual($first)
        ->and(DB::table('topic_words')->count())->toBe(1);
});

it('đồng bộ bảng topics từ TopicCatalog, không từ file JSON', function (): void {
    writeTopicFile($this->dir, 'tinh-yeu', [entry($this->word)]);

    $this->artisan('topics:import', ['--path' => $this->dir])->assertSuccessful();

    // Cả 16 chủ đề của danh mục, không chỉ chủ đề có file.
    expect(Topic::count())->toBe(16)
        ->and(Topic::where('slug', 'tinh-yeu')->value('name'))->toBe('Tình yêu & cảm xúc')
        ->and(Topic::where('slug', 'thuc-an')->value('emoji'))->toBe('🍜');
});

/*
 * Ba test dưới đây khoá đúng ba cách mà bản kế hoạch ĐẦU TIÊN sẽ mất dữ liệu
 * production. Chúng là lý do phase này tồn tại.
 */

it('KHÔNG xoá gì khi thư mục không có file JSON nào', function (): void {
    writeTopicFile($this->dir, 'tinh-yeu', [entry($this->word)]);
    $this->artisan('topics:import', ['--path' => $this->dir])->assertSuccessful();

    File::delete("{$this->dir}/tinh-yeu.json");

    // Thư mục JSON không vào được image là ca thật — `storage/app/` đã dẫm đúng
    // bẫy đó. "0 file" KHÔNG được phép có nghĩa là "xoá hết".
    $this->artisan('topics:import', ['--path' => $this->dir])->assertFailed();

    expect(DB::table('topic_words')->count())->toBe(1);
});

it('chỉ xoá dòng thừa TRONG chủ đề có file, không đụng chủ đề khác', function (): void {
    $second = DictionaryWord::factory()->create([
        'simplified' => '猫', 'pinyin_numbered' => 'mao1',
        'definitions_vi' => ['con mèo'], 'han_viet' => 'miêu',
        'han_viet_status' => DictionaryWord::STATUS_OK,
    ]);

    writeTopicFile($this->dir, 'tinh-yeu', [entry($this->word)]);
    writeTopicFile($this->dir, 'dong-thuc-vat', [entry($second)]);

    $this->artisan('topics:import', ['--path' => $this->dir])->assertSuccessful();
    expect(DB::table('topic_words')->count())->toBe(2);

    // Chạy lại CHỈ một chủ đề — đúng kịch bản chạy thử sau khi sinh lại một
    // chủ đề. Bản đầu xoá "dòng không còn trong JSON" trên TOÀN BẢNG, nên lệnh
    // này sẽ xoá sạch 15 chủ đề kia rồi exit 0.
    $this->artisan('topics:import', ['--path' => $this->dir, '--topic' => 'tinh-yeu'])->assertSuccessful();

    $animalTopic = Topic::where('slug', 'dong-thuc-vat')->firstOrFail();

    expect(DB::table('topic_words')->count())->toBe(2)
        ->and(DB::table('topic_words')->where('topic_id', $animalTopic->id)->count())->toBe(1);
});

it('rollback toàn bộ khi một chủ đề không đạt độ phủ', function (): void {
    /*
     * Thứ tự file QUAN TRỌNG. `glob()` sắp theo tên, nên chủ đề hỏng phải sắp
     * SAU chủ đề ghi được — `thuc-an` < `tinh-yeu` < `van-phong`.
     *
     * Bản đầu của test này dùng `thuc-an` làm file hỏng: nó chạy TRƯỚC, ném ra
     * trước khi một dòng nào được ghi, nên test xanh kể cả khi gỡ hẳn
     * `DB::transaction` khỏi lệnh. Nó khoá đúng thứ nó tồn tại để khoá — bằng
     * cách không kiểm gì cả.
     */
    writeTopicFile($this->dir, 'tinh-yeu', [entry($this->word)]);
    $this->artisan('topics:import', ['--path' => $this->dir])->assertSuccessful();

    $before = DB::table('topic_words')->get()->toArray();
    expect($before)->toHaveCount(1);

    $newWord = DictionaryWord::factory()->create([
        'simplified' => '恋', 'pinyin_numbered' => 'lian4',
        'definitions_vi' => ['yêu; luyến ái'], 'han_viet' => 'luyến',
        'han_viet_status' => DictionaryWord::STATUS_OK,
    ]);

    // `tinh-yeu` giờ THÊM một từ mới (ghi thật vào bảng), rồi `van-phong` — sắp
    // sau nó — hỏng. Dòng mới phải biến mất cùng transaction.
    writeTopicFile($this->dir, 'tinh-yeu', [entry($this->word, 1), entry($newWord, 2)]);
    writeTopicFile($this->dir, 'van-phong', [
        ['zh' => '沒有這個字', 'pinyin' => 'mei2 you3', 'rank' => 1, 'batch' => 1],
    ]);

    $this->artisan('topics:import', ['--path' => $this->dir])->assertFailed();

    expect(DB::table('topic_words')->get()->toArray())->toEqual($before)
        ->and(DB::table('topic_words')->where('word_id', $newWord->id)->exists())->toBeFalse();
});

it('từ chối file sai schema mà KHÔNG ghi dòng nào', function (array $words, ?int $version): void {
    writeTopicFile($this->dir, 'tinh-yeu', $words, $version);

    $this->artisan('topics:import', ['--path' => $this->dir])->assertFailed();

    expect(DB::table('topic_words')->count())->toBe(0)
        ->and(Topic::count())->toBe(0);
})->with([
    'thiếu pinyin' => [[['zh' => '爱', 'rank' => 1, 'batch' => 1]], null],
    'thiếu rank' => [[['zh' => '爱', 'pinyin' => 'ai4', 'batch' => 1]], null],
    'words rỗng' => [[], null],
    'prompt_version cũ' => [[['zh' => '爱', 'pinyin' => 'ai4', 'rank' => 1, 'batch' => 1]], 999],
]);

it('phân giải đúng cách đọc cho chữ đa âm', function (): void {
    // `行` có hai mục cùng chữ khác âm. Khoá tự nhiên là CẶP (chữ, pinyin số),
    // nên hai dòng JSON khác pinyin phải ra hai mục khác nhau.
    $hang = DictionaryWord::factory()->create([
        'simplified' => '行', 'pinyin_numbered' => 'hang2',
        'definitions_vi' => ['hàng; dãy'], 'han_viet' => 'hàng',
        'han_viet_status' => DictionaryWord::STATUS_OK,
    ]);
    $xing = DictionaryWord::factory()->create([
        'simplified' => '行', 'pinyin_numbered' => 'xing2',
        'definitions_vi' => ['đi; được'], 'han_viet' => 'hành',
        'han_viet_status' => DictionaryWord::STATUS_OK,
    ]);

    writeTopicFile($this->dir, 'du-lich', [entry($xing, 1), entry($hang, 2)]);

    $this->artisan('topics:import', ['--path' => $this->dir])->assertSuccessful();

    $ids = DB::table('topic_words')->orderBy('rank')->pluck('word_id')->all();

    expect($ids)->toBe([$xing->id, $hang->id]);
});

it('loại từ thiếu âm Hán-Việt vì ôn tập không dùng được nó', function (): void {
    $broken = DictionaryWord::factory()->create([
        'simplified' => '龘', 'pinyin_numbered' => 'da2',
        'definitions_vi' => ['rồng bay'], 'han_viet' => null,
        'han_viet_status' => DictionaryWord::STATUS_MISSING,
    ]);

    // Một từ hỏng trong hai → độ phủ 50% < 95% → fail, không ghi gì.
    writeTopicFile($this->dir, 'tinh-yeu', [entry($this->word, 1), entry($broken, 2)]);

    $this->artisan('topics:import', ['--path' => $this->dir])->assertFailed();

    expect(DB::table('topic_words')->count())->toBe(0);
});

it('dừng với thông báo rõ khi chưa chạy cvdict:import', function (): void {
    DictionaryWord::query()->update(['definitions_vi' => null]);

    writeTopicFile($this->dir, 'tinh-yeu', [entry($this->word)]);

    $this->artisan('topics:import', ['--path' => $this->dir])
        ->expectsOutputToContain('cvdict:import')
        ->assertFailed();
});
