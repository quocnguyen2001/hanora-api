<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\SearchQueryInterpretation;
use App\Services\Dictionary\Search\Interpretation;
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
function interpretResponse(array $words, ?array $translation = null): array
{
    $payload = ['words' => $words];

    if ($translation !== null) {
        $payload['translation'] = $translation;
    }

    return [
        'usage' => ['total_input_tokens' => 40, 'total_output_tokens' => 20],
        'steps' => [
            ['type' => 'thought', 'signature' => 'x'],
            ['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => json_encode($payload)],
            ]],
        ],
    ];
}

function interpret(string $query, string $mode = 'vi'): Interpretation
{
    return app(SearchInterpreter::class)->interpret($query, $mode);
}

/** @return list<int> */
function interpretIds(string $query, string $mode = 'vi'): array
{
    return interpret($query, $mode)->ids;
}

it('diễn giải truy vấn rồi trả id mục từ trong corpus', function (): void {
    $expected = DictionaryWord::where('simplified', '学习')->value('id');
    Http::fake(['*' => Http::response(interpretResponse(['学习']))]);

    expect(interpretIds('tôi muốn học'))->toBe([$expected]);
});

it('không gọi mạng lần thứ hai cho cùng một truy vấn', function (): void {
    Http::fake(['*' => Http::response(interpretResponse(['学习']))]);

    $first = interpretIds('tôi muốn học');
    $second = interpretIds('tôi muốn học');

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
    expect(interpretIds('học sinh và học tập'))->toBe([$real['学生'], $real['学习']]);
});

it('cache cả kết quả rỗng do AI chủ động trả về', function (): void {
    // Truy vấn rác phải được hỏi ĐÚNG MỘT LẦN. Không cache thì mỗi lần gõ nhầm
    // là 3,5 giây và một khoản tiền.
    Http::fake(['*' => Http::response(interpretResponse([]))]);

    expect(interpretIds('asdfghjkl'))->toBe([])
        ->and(interpretIds('asdfghjkl'))->toBe([]);

    Http::assertSentCount(1);
    expect(SearchQueryInterpretation::where('query_normalized', 'asdfghjkl')->exists())->toBeTrue();
});

it('KHÔNG cache khi lời gọi hỏng', function (): void {
    // Cache ở đây vĩnh viễn. Đóng băng một sự cố mạng 30 giây thành "truy vấn
    // này không có kết quả, mãi mãi" là cách hỏng tệ nhất lớp này có thể tạo ra.
    Http::fake(fn () => throw new ConnectionException('timeout'));

    // `failed`, KHÔNG phải rỗng. Controller dùng khác biệt này để đặt `no-store`.
    expect(interpret('anh yêu em')->failed)->toBeTrue();
    expect(SearchQueryInterpretation::count())->toBe(0);
});

it('trả rỗng cho truy vấn trắng, không gọi mạng', function (): void {
    Http::fake();

    expect(interpretIds('   '))->toBe([]);
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

describe('câu dịch cho truy vấn dạng câu', function (): void {
    it('giữ câu dịch dù nó KHÔNG có trong corpus', function (): void {
        /*
         * Hồi quy cho lỗi người dùng báo: `bạn có nhớ tôi không?` trả về
         * 你 / 记得 / 我 / 想念 — các mảnh của câu thay vì câu trả lời.
         *
         * AI thực ra ĐÃ trả về `你还记得我吗`, nhưng luật tra ngược corpus vứt nó
         * đi. Luật đó đúng cho từ ghép và sai cho câu: một câu KHÔNG BAO GIỜ là
         * mục từ điển, nên chống bịa vô tình giết đúng câu trả lời hữu ích nhất.
         */
        Http::fake(['*' => Http::response(interpretResponse(['记得', '想念'], [
            'zh' => '你还记得我吗？',
            'pinyin' => 'nǐ hái jìde wǒ ma?',
            'vi' => 'bạn có nhớ tôi không?',
        ]))]);

        $result = interpret('bạn có nhớ tôi không?');

        expect($result->translation)->not->toBeNull()
            ->and($result->translation['zh'])->toBe('你还记得我吗？')
            ->and($result->translation['pinyin'])->toBe('nǐ hái jìde wǒ ma?');
    });

    it('cache câu dịch cùng danh sách từ', function (): void {
        Http::fake(['*' => Http::response(interpretResponse(['学习'], [
            'zh' => '我想学习', 'pinyin' => 'wǒ xiǎng xuéxí', 'vi' => 'tôi muốn học',
        ]))]);

        interpret('tôi muốn học');
        $second = interpret('tôi muốn học');

        Http::assertSentCount(1);
        expect($second->translation['zh'])->toBe('我想学习');
    });

    it('không có câu dịch khi truy vấn chỉ là một từ', function (): void {
        Http::fake(['*' => Http::response(interpretResponse(['学生']))]);

        expect(interpret('học sinh')->translation)->toBeNull();
    });

    describe('loại câu dịch lệch hình dạng', function (): void {
        it('khi zh không chứa chữ Hán nào', function (): void {
            // Model trả một câu tiếng Việt vào ô `zh` là ca hỏng duy nhất bắt
            // được mà không cần thêm một lời gọi nữa — câu thì không tra ngược
            // corpus được.
            Http::fake(['*' => Http::response(interpretResponse(['学习'], [
                'zh' => 'toi muon hoc', 'pinyin' => 'x', 'vi' => 'y',
            ]))]);

            expect(interpret('tôi muốn học')->translation)->toBeNull();
        });

        it('khi thiếu pinyin', function (): void {
            Http::fake(['*' => Http::response(interpretResponse(['学习'], [
                'zh' => '我想学习', 'pinyin' => '', 'vi' => 'y',
            ]))]);

            expect(interpret('tôi muốn học')->translation)->toBeNull();
        });

        it('khi translation mang kiểu sai hoàn toàn', function (): void {
            Http::fake(['*' => Http::response(interpretResponse(['学习'], []))]);

            expect(interpret('tôi muốn học')->translation)->toBeNull();
        });
    });

    it('coi là có kết quả dù chỉ có câu dịch, không có từ nào lọt', function (): void {
        // Mọi từ AI đề xuất đều bịa, nhưng câu dịch vẫn dùng được. Trả rỗng ở
        // đây là quay về đúng hành vi mà lỗi này sinh ra.
        Http::fake(['*' => Http::response(interpretResponse(['词不存在'], [
            'zh' => '你还记得我吗？', 'pinyin' => 'nǐ hái jìde wǒ ma?', 'vi' => 'x',
        ]))]);

        $result = interpret('bạn có nhớ tôi không?');

        expect($result->ids)->toBe([])
            ->and($result->isEmpty())->toBeFalse();
    });
});
