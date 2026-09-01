<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Services\Dictionary\Enrichment\EnrichmentValidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);

    $this->word = DictionaryWord::where('simplified', '学习')->firstOrFail();
});

function validPayload(array $override = []): array
{
    return array_merge([
        'senses' => [['pos' => 'động từ', 'vi' => 'học, học tập', 'note' => null]],
        'examples' => [['zh' => '我喜欢学习。', 'pinyin' => 'wǒ xǐhuān xuéxí', 'vi' => 'Tôi thích học.']],
        'characters' => [
            ['char' => '学', 'radical' => '子', 'stroke_count' => 8, 'meaning_vi' => 'học'],
            ['char' => '习', 'radical' => '乙', 'stroke_count' => 3, 'meaning_vi' => 'luyện tập'],
        ],
        'related_words' => [['simplified' => '学生', 'pinyin' => 'xuéshēng', 'vi' => 'học sinh']],
        'idioms' => [],
        'usage_note' => 'Dùng cho cả việc học ở trường lẫn tự học.',
    ], $override);
}

function check(array $payload): ?array
{
    return app(EnrichmentValidator::class)->validate($payload, test()->word);
}

it('cho payload hợp lệ đi qua nguyên vẹn', function (): void {
    $result = check(validPayload());

    expect($result['senses'])->toHaveCount(1)
        ->and($result['examples'])->toHaveCount(1)
        ->and($result['characters'])->toHaveCount(2)
        ->and($result['related_words'])->toHaveCount(1)
        ->and($result['usage_note'])->toBe('Dùng cho cả việc học ở trường lẫn tự học.');
});

describe('loại chữ Hán do AI bịa', function (): void {
    it('loại từ ghép không có trong corpus, giữ phần còn lại', function (): void {
        $result = check(validPayload(['related_words' => [
            ['simplified' => '这个词不存在', 'pinyin' => 'x', 'vi' => 'bịa'],
            ['simplified' => '学生', 'pinyin' => 'xuéshēng', 'vi' => 'học sinh'],
        ]]));

        expect($result['related_words'])->toHaveCount(1)
            ->and($result['related_words'][0]['simplified'])->toBe('学生');
    });

    it('loại thành ngữ không có trong corpus', function (): void {
        $result = check(validPayload(['idioms' => [
            ['simplified' => '不但……而且……', 'pinyin' => 'x', 'vi' => 'cụm ngữ pháp'],
        ]]));

        expect($result['idioms'])->toBeEmpty();
    });

    it('loại chữ không thuộc chính từ đang tra', function (): void {
        $result = check(validPayload(['characters' => [
            ['char' => '学', 'radical' => '子', 'stroke_count' => 8, 'meaning_vi' => 'học'],
            ['char' => '猫', 'radical' => '犭', 'stroke_count' => 11, 'meaning_vi' => 'mèo'],
        ]]));

        expect($result['characters'])->toHaveCount(1)
            ->and($result['characters'][0]['char'])->toBe('学');
    });
});

it('loại câu ví dụ không chứa từ đang tra', function (): void {
    // Một câu tiếng Trung ngẫu nhiên đặt cạnh mục từ không minh hoạ được gì.
    $result = check(validPayload(['examples' => [
        ['zh' => '今天天气很好。', 'pinyin' => 'x', 'vi' => 'Hôm nay trời đẹp.'],
        ['zh' => '我在学习中文。', 'pinyin' => 'y', 'vi' => 'Tôi đang học tiếng Trung.'],
    ]]));

    expect($result['examples'])->toHaveCount(1)
        ->and($result['examples'][0]['zh'])->toBe('我在学习中文。');
});

describe('payload không đủ dùng thì trả null', function (): void {
    it('khi senses rỗng', function (): void {
        // Cache một bản ghi rỗng vĩnh viễn = từ đó KHÔNG BAO GIỜ được sinh lại.
        expect(check(validPayload(['senses' => []])))->toBeNull();
    });

    it('khi senses thiếu trường bắt buộc', function (): void {
        expect(check(validPayload(['senses' => [['pos' => 'động từ']]])))->toBeNull();
    });

    it('khi senses chỉ có chuỗi rỗng', function (): void {
        expect(check(validPayload(['senses' => [['pos' => '  ', 'vi' => '  ']]])))->toBeNull();
    });
});

describe('không ném với payload lệch hình dạng', function (): void {
    it('thiếu hẳn các khóa tùy chọn', function (): void {
        $result = check(['senses' => [['pos' => 'động từ', 'vi' => 'học']]]);

        expect($result['examples'])->toBe([])
            ->and($result['idioms'])->toBe([])
            ->and($result['related_words'])->toBe([])
            ->and($result['usage_note'])->toBeNull();
    });

    it('khóa mang kiểu sai hoàn toàn', function (): void {
        $result = check(validPayload([
            'examples' => 'đáng lẽ phải là mảng',
            'characters' => 42,
            'idioms' => null,
        ]));

        expect($result['examples'])->toBe([])
            ->and($result['characters'])->toBe([])
            ->and($result['idioms'])->toBe([]);
    });

    it('stroke_count không phải số', function (): void {
        $result = check(validPayload(['characters' => [
            ['char' => '学', 'radical' => '子', 'stroke_count' => 'tám', 'meaning_vi' => 'học'],
        ]]));

        expect($result['characters'])->toBe([]);
    });
});

it('cắt trần số phần tử', function (): void {
    $many = array_map(
        fn (int $i): array => ['pos' => 'danh từ', 'vi' => "nghĩa {$i}", 'note' => null],
        range(1, 20),
    );

    expect(check(validPayload(['senses' => $many]))['senses'])->toHaveCount(8);
});

it('khử trùng từ ghép lặp lại', function (): void {
    $result = check(validPayload(['related_words' => [
        ['simplified' => '学生', 'pinyin' => 'xuéshēng', 'vi' => 'học sinh'],
        ['simplified' => '学生', 'pinyin' => 'xuéshēng', 'vi' => 'học trò'],
    ]]));

    expect($result['related_words'])->toHaveCount(1);
});

it('gắn word_id cho từ tra được, để FE mở được trang chi tiết', function (): void {
    // Truy vấn tra ngược VỐN ĐÃ chạy để loại từ model bịa ra. Giữ lại `id` mà nó
    // đọc về là miễn phí, và đó là thứ biến danh sách từ ghép từ "để nhìn"
    // thành bấm được.
    $expected = DictionaryWord::query()->where('simplified', '学生')->value('id');

    $result = check(validPayload(['related_words' => [
        ['simplified' => '学生', 'pinyin' => 'xuéshēng', 'vi' => 'học sinh'],
    ]]));

    expect($result['related_words'][0]['word_id'])->toBe($expected);
});

it('không để lọt mục nào thiếu word_id', function (): void {
    // Nhánh lọc `isset($existing[...])` đã bỏ mọi từ không tra được, nên tới
    // bước dựng mảng thì id chắc chắn tồn tại. Test này khoá bất biến đó: ai nới
    // lỏng bộ lọc thì mục thiếu id sẽ lọt xuống FE thành một link hỏng.
    $result = check(validPayload(['related_words' => [
        ['simplified' => '学生', 'pinyin' => 'xuéshēng', 'vi' => 'học sinh'],
        ['simplified' => '这个词不存在', 'pinyin' => 'x', 'vi' => 'bịa'],
    ]]));

    foreach ([...$result['related_words'], ...$result['idioms']] as $item) {
        expect($item['word_id'])->toBeInt();
    }
});

it('tra ngược corpus bằng đúng MỘT truy vấn', function (): void {
    // N+1 ở đây nhân 123.646 từ × 12 đề xuất = 1,5 triệu truy vấn mỗi lần pre-warm.
    $related = array_map(
        fn (string $w): array => ['simplified' => $w, 'pinyin' => 'x', 'vi' => 'y'],
        ['学生', '学', '习', '银行', '东西', '沙发', '好', '女'],
    );

    $queries = 0;
    DB::listen(function ($q) use (&$queries): void {
        if (str_contains($q->sql, 'from "dictionary_words"')) {
            $queries++;
        }
    });

    check(validPayload(['related_words' => $related, 'idioms' => [
        ['simplified' => '一', 'pinyin' => 'yī', 'vi' => 'một'],
    ]]));

    expect($queries)->toBe(1);
});

it('loại chính từ đang tra khỏi danh sách từ liên quan', function (): void {
    // Đo trên bản sinh thật: 一共 tự liệt kê 一共 ở vị trí đầu, chiếm một suất
    // trong bốn suất hiển thị để nói lại thứ đang ở tiêu đề màn hình.
    $result = check(validPayload(['related_words' => [
        ['simplified' => '学习', 'pinyin' => 'xuéxí', 'vi' => 'học tập'],
        ['simplified' => '学生', 'pinyin' => 'xuéshēng', 'vi' => 'học sinh'],
    ]]));

    expect($result['related_words'])->toHaveCount(1)
        ->and($result['related_words'][0]['simplified'])->toBe('学生');
});
