<?php

declare(strict_types=1);

use App\Jobs\OptimizeWordGlosses;
use App\Models\DictionaryWord;
use App\Services\Dictionary\Glosses\GlossPrompt;
use App\Services\Gemini\GeminiClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    // Hai fixture, cùng khuôn `VietnameseMeaningSearchTest`: `cedict-vi-sample`
    // là tập có nghĩa tiếng Việt thật trong CVDICT.
    foreach (['cedict-sample.u8', 'cedict-vi-sample.u8'] as $fixture) {
        Artisan::call('dictionary:import', [
            '--path' => base_path("tests/Fixtures/{$fixture}"),
            '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
            '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
        ]);
    }

    Artisan::call('cvdict:import', [
        '--path' => base_path('tests/Fixtures/cvdict-sample-search.u8'),
        '--skip-checksum' => true,
    ]);

    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-3.1-flash-lite']);
});

function glossResponse(array $items): array
{
    return [
        'usage' => ['total_input_tokens' => 900, 'total_output_tokens' => 300],
        'steps' => [
            ['type' => 'thought', 'signature' => 'x'],
            ['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => json_encode(['items' => $items])],
            ]],
        ],
    ];
}

function runGlossJob(array $ids): void
{
    (new OptimizeWordGlosses($ids))->handle(app(GeminiClient::class), app(GlossPrompt::class));
}

describe('ghi vào cột AI, không đụng CVDICT', function (): void {
    it('ghi nghĩa, nghĩa đầu, vector và phiên bản', function (): void {
        $word = DictionaryWord::whereNotNull('definitions_vi')->firstOrFail();
        $before = $word->definitions_vi;

        Http::fake(['*' => Http::response(glossResponse([
            ['i' => 0, 'glosses' => ['học tập', 'nghiên cứu']],
        ]))]);

        runGlossJob([$word->id]);

        $row = DB::table('dictionary_words')->where('id', $word->id)->first();

        expect(json_decode((string) $row->definitions_vi_ai, true))->toBe(['học tập', 'nghiên cứu'])
            ->and($row->definitions_vi_ai_first)->toBe('học tập')
            // `f_unaccent` phải chạy, nếu không nhánh không dấu khớp trượt.
            ->and($row->definitions_vi_ai_first_plain)->toBe('hoc tap')
            ->and($row->search_vi_ai_tsv)->not->toBeNull()
            ->and($row->search_vi_ai_plain_tsv)->not->toBeNull()
            ->and($row->vi_ai_version)->toBe(GlossPrompt::VERSION)
            ->and($row->vi_ai_model)->toBe('gemini-3.1-flash-lite');

        // CVDICT giữ nguyên — đó là toàn bộ lý do dùng cột riêng.
        expect($word->fresh()->definitions_vi)->toBe($before);
    });

    it('vector không dấu khớp được truy vấn bỏ dấu', function (): void {
        $word = DictionaryWord::whereNotNull('definitions_vi')->firstOrFail();

        Http::fake(['*' => Http::response(glossResponse([['i' => 0, 'glosses' => ['học tập']]]))]);
        runGlossJob([$word->id]);

        $hit = DB::table('dictionary_words')
            ->where('id', $word->id)
            ->whereRaw("search_vi_ai_plain_tsv @@ plainto_tsquery('simple', f_unaccent(?))", ['hoc tap'])
            ->exists();

        expect($hit)->toBeTrue();
    });
});

describe('không tin payload của model', function (): void {
    it('bỏ mục có chỉ số ngoài lô', function (): void {
        $word = DictionaryWord::whereNotNull('definitions_vi')->firstOrFail();

        // Model trả chỉ số 7 cho một lô 1 phần tử. Đoán xem nó định nói mục nào
        // là cách ghi nghĩa của từ A vào từ B.
        Http::fake(['*' => Http::response(glossResponse([['i' => 7, 'glosses' => ['sai']]]))]);
        runGlossJob([$word->id]);

        expect($word->fresh()->vi_ai_version)->toBeNull();
    });

    it('bỏ mục mà mọi nghĩa đều bị lọc sạch', function (): void {
        $word = DictionaryWord::whereNotNull('definitions_vi')->firstOrFail();

        Http::fake(['*' => Http::response(glossResponse([['i' => 0, 'glosses' => ['嗎啡', '您']]]))]);
        runGlossJob([$word->id]);

        expect($word->fresh()->vi_ai_version)->toBeNull();
    });

    it('không ghi gì khi API hỏng', function (): void {
        $word = DictionaryWord::whereNotNull('definitions_vi')->firstOrFail();

        Http::fake(['*' => Http::response('boom', 500)]);
        runGlossJob([$word->id]);

        expect($word->fresh()->vi_ai_version)->toBeNull();
    });

    it('không ném khi payload lệch hình dạng', function (): void {
        $word = DictionaryWord::whereNotNull('definitions_vi')->firstOrFail();

        // `items` là chuỗi thay vì mảng — dựng thẳng, không qua `glossResponse()`
        // vì helper đó nhận `array` theo đúng hình dạng hợp lệ.
        Http::fake(['*' => Http::response([
            'steps' => [['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => json_encode(['items' => 'đáng lẽ là mảng'])],
            ]]],
        ])]);

        expect(fn () => runGlossJob([$word->id]))->not->toThrow(Throwable::class);
    });
});

describe('lệnh xếp hàng theo lô', function (): void {
    beforeEach(fn () => Queue::fake());

    it('gộp đúng BATCH từ vào một job', function (): void {
        Artisan::call('dictionary:optimize-glosses', ['--all' => true]);

        $expected = DictionaryWord::whereNotNull('definitions_vi')->count();
        $jobs = (int) ceil($expected / GlossPrompt::BATCH);

        expect($expected)->toBeGreaterThan(0);
        Queue::assertPushed(OptimizeWordGlosses::class, $jobs);
    });

    it('KHÔNG bỏ rơi lô cuối chưa đầy', function (): void {
        // Lô cuối gần như luôn không đầy; bỏ nó là bỏ tới 19 từ mỗi lần chạy.
        Artisan::call('dictionary:optimize-glosses', ['--limit' => GlossPrompt::BATCH + 1]);

        Queue::assertPushed(OptimizeWordGlosses::class, 2);
    });

    it('bỏ qua mục không có nghĩa tiếng Việt', function (): void {
        Artisan::call('dictionary:optimize-glosses', ['--all' => true]);

        Queue::assertPushed(OptimizeWordGlosses::class, function (OptimizeWordGlosses $job): bool {
            return DictionaryWord::whereIn('id', $job->wordIds)
                ->whereNull('definitions_vi')->doesntExist();
        });
    });

    it('bỏ qua mục đã dọn ở phiên bản hiện hành', function (): void {
        DictionaryWord::whereNotNull('definitions_vi')
            ->update(['vi_ai_version' => GlossPrompt::VERSION]);

        Artisan::call('dictionary:optimize-glosses', ['--all' => true]);

        Queue::assertNothingPushed();
    });

    it('--force dọn lại cả mục đã xong', function (): void {
        DictionaryWord::whereNotNull('definitions_vi')
            ->update(['vi_ai_version' => GlossPrompt::VERSION]);

        Artisan::call('dictionary:optimize-glosses', ['--force' => true]);

        Queue::assertPushed(OptimizeWordGlosses::class);
    });

    it('--pretend không xếp job nào', function (): void {
        Artisan::call('dictionary:optimize-glosses', ['--all' => true, '--pretend' => true]);

        Queue::assertNothingPushed();
    });

    it('xếp lên queue riêng glosses', function (): void {
        Artisan::call('dictionary:optimize-glosses', ['--limit' => 1]);

        Queue::assertPushed(
            OptimizeWordGlosses::class,
            fn (OptimizeWordGlosses $job): bool => $job->queue === 'glosses',
        );
    });
});

it('nhánh AI tìm được từ mà nghĩa CVDICT không khớp', function (): void {
    /*
     * Đây là toàn bộ lý do của lớp này. Đặt nghĩa CVDICT thành thứ không ai gõ,
     * rồi dọn bằng AI thành nghĩa người ta thật sự gõ — nhánh cũ trượt, nhánh
     * AI trúng.
     */
    $word = DictionaryWord::whereNotNull('definitions_vi')->firstOrFail();
    DB::table('dictionary_words')->where('id', $word->id)
        ->update(['definitions_vi' => json_encode(['xe taxi (viết tắt)'])]);

    Http::fake(['*' => Http::response(glossResponse([['i' => 0, 'glosses' => ['trợ từ sở hữu']]]))]);
    runGlossJob([$word->id]);

    $found = DB::table('dictionary_words')
        ->where('id', $word->id)
        ->whereRaw("search_vi_ai_tsv @@ plainto_tsquery('simple', ?)", ['trợ từ sở hữu'])
        ->exists();

    $oldMisses = DB::table('dictionary_words')
        ->where('id', $word->id)
        ->whereRaw("search_vi_tsv @@ plainto_tsquery('simple', ?)", ['trợ từ sở hữu'])
        ->doesntExist();

    expect($found)->toBeTrue()->and($oldMisses)->toBeTrue();
});
