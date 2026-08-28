<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\SearchQueryInterpretation;
use App\Services\Dictionary\Search\SearchInterpreter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);

    config([
        'services.gemini.key' => 'test-key',
        'services.gemini.model' => 'gemini-3.1-flash-lite',
    ]);
});

/** Hình dạng response thật của Interactions API — bước `thought` đứng trước. */
function interpretResponse(array $words): array
{
    return [
        'usage' => ['total_input_tokens' => 40, 'total_output_tokens' => 20],
        'steps' => [
            ['type' => 'thought', 'signature' => 'x'],
            ['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => json_encode(['words' => $words])],
            ]],
        ],
    ];
}

function interpret(string $query, string $mode = 'vi'): ?array
{
    return app(SearchInterpreter::class)->interpret($query, $mode);
}

it('diễn giải truy vấn rồi trả id mục từ trong corpus', function (): void {
    $expected = DictionaryWord::where('simplified', '学习')->value('id');
    Http::fake(['*' => Http::response(interpretResponse(['学习']))]);

    expect(interpret('tôi muốn học'))->toBe([$expected]);
});

it('không gọi mạng lần thứ hai cho cùng một truy vấn', function (): void {
    Http::fake(['*' => Http::response(interpretResponse(['学习']))]);

    $first = interpret('tôi muốn học');
    $second = interpret('tôi muốn học');

    expect($second)->toBe($first);
    Http::assertSentCount(1);
});

it('coi khác biệt hoa thường và khoảng trắng là cùng một truy vấn', function (): void {
    Http::fake(['*' => Http::response(interpretResponse(['学习']))]);

    interpret('Tôi  Muốn   Học');
    interpret('  tôi muốn học ');

    Http::assertSentCount(1);
});

it('tách cache theo mode', function (): void {
    // `học` ở mode vi và mode cn là hai ý định khác nhau, không dùng chung đáp án.
    Http::fake(['*' => Http::response(interpretResponse(['学习']))]);

    interpret('hoc', 'vi');
    interpret('hoc', 'cn');

    Http::assertSentCount(2);
});

it('loại chữ Hán do AI bịa, giữ nguyên thứ tự của phần còn lại', function (): void {
    $real = DictionaryWord::whereIn('simplified', ['学习', '学生'])
        ->pluck('id', 'simplified');

    Http::fake(['*' => Http::response(interpretResponse([
        '学生',            // có thật
        '不但……而且……',   // cụm ngữ pháp, không phải mục từ
        '学习',            // có thật
        '这个词不存在',    // bịa
    ]))]);

    // Thứ tự AI được giữ: 学生 trước 学习, dù id trong bảng ngược lại.
    expect(interpret('học sinh và học tập'))->toBe([$real['学生'], $real['学习']]);
});

it('cache cả kết quả rỗng do AI chủ động trả về', function (): void {
    // Truy vấn rác phải được hỏi ĐÚNG MỘT LẦN. Không cache thì mỗi lần gõ nhầm
    // là 3,5 giây và một khoản tiền.
    Http::fake(['*' => Http::response(interpretResponse([]))]);

    expect(interpret('asdfghjkl'))->toBe([])
        ->and(interpret('asdfghjkl'))->toBe([]);

    Http::assertSentCount(1);
    expect(SearchQueryInterpretation::where('query_normalized', 'asdfghjkl')->exists())->toBeTrue();
});

it('KHÔNG cache khi lời gọi hỏng', function (): void {
    // Cache ở đây vĩnh viễn. Đóng băng một sự cố mạng 30 giây thành "truy vấn
    // này không có kết quả, mãi mãi" là cách hỏng tệ nhất lớp này có thể tạo ra.
    Http::fake(fn () => throw new ConnectionException('timeout'));

    // `null`, KHÔNG phải `[]`. Controller dùng khác biệt này để đặt `no-store`.
    expect(interpret('anh yêu em'))->toBeNull();
    expect(SearchQueryInterpretation::count())->toBe(0);
});

it('trả rỗng cho truy vấn trắng, không gọi mạng', function (): void {
    Http::fake();

    expect(interpret('   '))->toBe([]);
    Http::assertNothingSent();
});

it('đếm được số lượt trúng cache', function (): void {
    Http::fake(['*' => Http::response(interpretResponse(['学习']))]);

    interpret('tôi muốn học');   // ghi, chưa tính là hit
    interpret('tôi muốn học');   // hit 1
    interpret('tôi muốn học');   // hit 2

    expect(SearchQueryInterpretation::first()->hit_count)->toBe(2);
});

it('tra ngược corpus bằng đúng một truy vấn bất kể AI đề xuất bao nhiêu từ', function (): void {
    Http::fake(['*' => Http::response(interpretResponse(
        ['学习', '学生', '学', '习', '银行', '东西', '沙发', '好']
    ))]);

    $queries = 0;
    DB::listen(function ($q) use (&$queries): void {
        if (str_contains($q->sql, 'from "dictionary_words"')) {
            $queries++;
        }
    });

    interpret('nhiều từ một lúc');

    expect($queries)->toBe(1);
});

it('ghi đè thay vì đâm vào unique index khi hai lượt cùng ghi', function (): void {
    /*
     * Cuộc đua thật: hai request cùng trượt cache, cùng gọi Gemini 3,5 giây,
     * rồi cùng ghi. Không mô phỏng được hai tiến trình trong một test, nên đây
     * kiểm ĐÚNG cái mà cuộc đua chạm vào — ghi hai lần lên cùng một khóa.
     *
     * Với `create()` lượt thứ hai ném QueryException và người dùng ăn 500.
     */
    $write = fn (array $ids) => SearchQueryInterpretation::query()->upsert(
        [[
            'query_normalized' => 'chạy đua', 'mode' => 'vi',
            'word_ids' => json_encode($ids), 'model' => 'gemini-3.1-flash-lite',
            'prompt_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]],
        ['query_normalized', 'mode'],
        ['word_ids', 'model', 'prompt_version', 'updated_at'],
    );

    $write([1]);
    $write([1, 2]);

    $rows = SearchQueryInterpretation::where('query_normalized', 'chạy đua')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->word_ids)->toBe([1, 2]);
});
