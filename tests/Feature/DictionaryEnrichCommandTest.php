<?php

declare(strict_types=1);

use App\Jobs\GenerateWordEnrichment;
use App\Models\DictionaryWord;
use App\Models\DictionaryWordEnrichment;
use App\Services\Dictionary\Enrichment\EnrichmentPrompt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);

    Queue::fake();
});

function markReady(string $simplified, int $promptVersion = EnrichmentPrompt::VERSION): DictionaryWord
{
    $word = DictionaryWord::where('simplified', $simplified)->firstOrFail();

    DictionaryWordEnrichment::query()->create([
        'word_id' => $word->id,
        'status' => DictionaryWordEnrichment::STATUS_READY,
        'payload' => ['senses' => [['pos' => 'x', 'vi' => 'y']]],
        'model' => 'gemini-3.1-flash-lite',
        'prompt_version' => $promptVersion,
    ]);

    return $word;
}

it('chỉ xếp từ có hsk_level khi dùng --hsk', function (): void {
    $expected = DictionaryWord::whereNotNull('hsk_level')->count();

    Artisan::call('dictionary:enrich', ['--hsk' => true]);

    expect($expected)->toBeGreaterThan(0);
    Queue::assertPushed(GenerateWordEnrichment::class, $expected);
});

it('bỏ qua từ đã có nội dung dùng được', function (): void {
    $ready = markReady('学习');

    Artisan::call('dictionary:enrich');

    Queue::assertNotPushed(
        GenerateWordEnrichment::class,
        fn (GenerateWordEnrichment $job): bool => $job->wordId === $ready->id,
    );
});

it('chạy lại lần hai không xếp job nào khi mọi từ đã xong', function (): void {
    // Đây là thứ khiến pre-warm chạy lại được mà không trả tiền hai lần.
    DictionaryWord::query()->whereNotNull('hsk_level')->each(
        fn (DictionaryWord $w) => DictionaryWordEnrichment::query()->create([
            'word_id' => $w->id,
            'status' => DictionaryWordEnrichment::STATUS_READY,
            'payload' => ['senses' => [['pos' => 'x', 'vi' => 'y']]],
            'model' => 'm',
            'prompt_version' => EnrichmentPrompt::VERSION,
        ]),
    );

    Artisan::call('dictionary:enrich', ['--hsk' => true]);

    Queue::assertNothingPushed();
});

it('--force xếp cả từ đã xong', function (): void {
    $ready = markReady('学习');

    Artisan::call('dictionary:enrich', ['--force' => true]);

    Queue::assertPushed(
        GenerateWordEnrichment::class,
        fn (GenerateWordEnrichment $job): bool => $job->wordId === $ready->id,
    );
});

it('--stale chỉ chọn bản ghi sinh bằng prompt cũ', function (): void {
    $stale = markReady('学习', promptVersion: EnrichmentPrompt::VERSION - 1);
    markReady('学生');

    Artisan::call('dictionary:enrich', ['--stale' => true]);

    Queue::assertPushed(GenerateWordEnrichment::class, 1);
    Queue::assertPushed(
        GenerateWordEnrichment::class,
        fn (GenerateWordEnrichment $job): bool => $job->wordId === $stale->id,
    );
});

it('tôn trọng --limit', function (): void {
    Artisan::call('dictionary:enrich', ['--limit' => 2]);

    Queue::assertPushed(GenerateWordEnrichment::class, 2);
});

it('xếp lên queue riêng, không dùng chung default', function (): void {
    // Pre-warm 4.987 từ trên hàng đợi chung sẽ bắt người đang mở màn chi tiết
    // xếp sau toàn bộ số đó.
    Artisan::call('dictionary:enrich', ['--limit' => 1]);

    Queue::assertPushed(
        GenerateWordEnrichment::class,
        fn (GenerateWordEnrichment $job): bool => $job->queue === 'enrichment',
    );
});

it('báo rõ khi không có gì để làm', function (): void {
    Artisan::call('dictionary:enrich', ['--stale' => true]);

    expect(Artisan::output())->toContain('Không có từ nào cần sinh');
    Queue::assertNothingPushed();
});
