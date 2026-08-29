<?php

declare(strict_types=1);

use App\Jobs\ResolveWordIllustration;
use App\Models\DictionaryWord;
use App\Models\DictionaryWordIllustration;
use App\Models\User;
use App\Services\Illustration\IllustrationSelector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Artisan::call('dictionary:import', [
        '--path' => base_path('tests/Fixtures/cedict-sample.u8'),
        '--hsk' => base_path('tests/Fixtures/hsk-sample.json'),
        '--frequency' => base_path('tests/Fixtures/subtlex-sample.json'),
    ]);

    config([
        'services.pixabay.key' => 'test-key',
        'services.pixabay.min_total_hits' => 200,
        'services.pixabay.min_tag_matches' => 3,
        'services.pixabay.sample_size' => 5,
    ]);

    $this->user = User::factory()->create();
    $this->word = DictionaryWord::where('simplified', '学习')->firstOrFail();
});

function illustrationApi(?int $wordId = null): TestResponse
{
    $id = $wordId ?? test()->word->id;

    return test()->actingAs(test()->user, 'sanctum')
        ->getJson("/api/dictionary/words/{$id}/illustration");
}

/** Phản hồi qua cổng cho cả hai nhánh ngôn ngữ. */
function fakePassingPixabay(): void
{
    Http::fake(['pixabay.com/*' => Http::response([
        'totalHits' => 500,
        'hits' => array_map(fn (int $i): array => [
            'id' => 100 + $i,
            /*
             * Phải chứa CẢ token tiếng Trung (`学习`) lẫn gloss tiếng Anh đầu
             * tiên của CC-CEDICT cho từ này.
             *
             * `学习` là `/to learn/to study/`, nên gloss đối chiếu là `learn`
             * — KHÔNG phải `study`. Nhánh tiếng Anh tra gloss ĐẦU TIÊN sau khi
             * gỡ tiền tố `to `, nên tag thiếu `learn` sẽ đóng cổng.
             */
            'tags' => '学习, learn, 课堂',
            'previewURL' => "https://cdn.pixabay.com/photo/2014/02/01/17/28/x-{$i}_150.jpg",
            'pageURL' => "https://pixabay.com/zh/photos/x-{$i}/",
            'webformatWidth' => 640,
            'webformatHeight' => 426,
            'user' => 'NoName_13',
            'user_id' => 2364555,
        ], range(1, 5)),
    ])]);
}

it('yêu cầu đăng nhập', function (): void {
    $this->getJson("/api/dictionary/words/{$this->word->id}/illustration")->assertUnauthorized();
});

describe('lần gọi đầu', function (): void {
    it('trả 202, tạo bản ghi pending và xếp job', function (): void {
        Queue::fake();

        illustrationApi()
            ->assertStatus(202)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.status', 'pending')
            ->assertHeader('Retry-After', '3');

        Queue::assertPushed(ResolveWordIllustration::class);

        expect(DictionaryWordIllustration::where('word_id', $this->word->id)->first())
            ->not->toBeNull();
    });

    it('không cho CDN đóng băng trạng thái tạm', function (): void {
        Queue::fake();

        expect(illustrationApi()->headers->get('Cache-Control'))->toContain('no-store');
    });
});

describe('đã có kết luận', function (): void {
    it('trả ảnh kèm ghi công khi status ready', function (): void {
        DictionaryWordIllustration::create([
            'word_id' => $this->word->id,
            'status' => DictionaryWordIllustration::STATUS_READY,
            'image_url' => 'https://cdn.pixabay.com/photo/x/y_640.jpg',
            'preview_url' => 'https://cdn.pixabay.com/photo/x/y_150.jpg',
            'page_url' => 'https://pixabay.com/photos/y/',
            'author' => 'NoName_13',
            'author_url' => 'https://pixabay.com/users/NoName_13-2364555/',
            'width' => 640,
            'height' => 426,
            'gate_version' => IllustrationSelector::GATE_VERSION,
        ]);

        illustrationApi()
            ->assertOk()
            ->assertJsonPath('meta.status', 'ready')
            ->assertJsonPath('data.url', 'https://cdn.pixabay.com/photo/x/y_640.jpg')
            // Ghi công là nghĩa vụ ToS, không phải trường tuỳ chọn.
            ->assertJsonPath('data.author', 'NoName_13')
            ->assertJsonPath('data.page_url', 'https://pixabay.com/photos/y/')
            ->assertJsonPath('data.source', 'pixabay');
    });

    it('trả data null và cache PUBLIC khi cổng đã đóng', function (): void {
        // `none` là kết luận vĩnh viễn nên được cache như `ready`, khác hẳn
        // `unavailable` vốn là trạng thái tạm.
        DictionaryWordIllustration::create([
            'word_id' => $this->word->id,
            'status' => DictionaryWordIllustration::STATUS_NONE,
            'gate_version' => IllustrationSelector::GATE_VERSION,
        ]);

        $response = illustrationApi()
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.status', 'none');

        expect($response->headers->get('Cache-Control'))->toContain('public');
    });

    it('KHÔNG gọi Pixabay lần thứ hai cho từ đã có kết luận', function (): void {
        // Tiêu chí trung tâm của plan: mỗi từ resolve đúng một lần trong đời.
        Http::fake();

        foreach ([DictionaryWordIllustration::STATUS_READY, DictionaryWordIllustration::STATUS_NONE] as $status) {
            DictionaryWordIllustration::updateOrCreate(
                ['word_id' => $this->word->id],
                ['status' => $status, 'gate_version' => IllustrationSelector::GATE_VERSION],
            );

            illustrationApi()->assertOk();
        }

        Http::assertNothingSent();
    });

    it('resolve lại khi gate_version đã cũ', function (): void {
        // Siết ngưỡng cổng phải quét lại được bản ghi cũ, nếu không mọi lần
        // chỉnh luật chỉ áp dụng cho từ chưa ai mở.
        Queue::fake();

        DictionaryWordIllustration::create([
            'word_id' => $this->word->id,
            'status' => DictionaryWordIllustration::STATUS_NONE,
            'gate_version' => IllustrationSelector::GATE_VERSION - 1,
        ]);

        illustrationApi()->assertStatus(202);

        Queue::assertPushed(ResolveWordIllustration::class);
    });
});

describe('suy giảm êm', function (): void {
    it('không bao giờ 5xx và không xếp job khi thiếu key', function (): void {
        config(['services.pixabay.key' => null]);
        Queue::fake();
        Http::fake();

        illustrationApi()
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.status', 'unavailable');

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    });

    it('trả unavailable sau khi cạn lượt thử', function (): void {
        $illustration = DictionaryWordIllustration::create([
            'word_id' => $this->word->id,
            'status' => DictionaryWordIllustration::STATUS_FAILED,
        ]);
        $illustration->attempts = DictionaryWordIllustration::MAX_ATTEMPTS;
        $illustration->save();

        Queue::fake();

        illustrationApi()->assertOk()->assertJsonPath('meta.status', 'unavailable');

        Queue::assertNothingPushed();
    });
});

describe('job', function (): void {
    it('lưu ảnh và ghi công khi cổng mở', function (): void {
        fakePassingPixabay();

        (new ResolveWordIllustration($this->word->id))->handle(app(IllustrationSelector::class));

        $illustration = DictionaryWordIllustration::where('word_id', $this->word->id)->firstOrFail();

        expect($illustration->status)->toBe(DictionaryWordIllustration::STATUS_READY)
            ->and($illustration->image_url)->toEndWith('_640.jpg')
            ->and($illustration->author)->toBe('NoName_13')
            ->and($illustration->matched_query)->toBe('学习')
            ->and($illustration->attempts)->toBe(0);
    });

    it('ghi none mà KHÔNG tăng attempts khi cổng đóng', function (): void {
        Http::fake(['pixabay.com/*' => Http::response(['totalHits' => 3, 'hits' => []])]);

        (new ResolveWordIllustration($this->word->id))->handle(app(IllustrationSelector::class));

        $illustration = DictionaryWordIllustration::where('word_id', $this->word->id)->firstOrFail();

        expect($illustration->status)->toBe(DictionaryWordIllustration::STATUS_NONE)
            // Cổng đóng là THÀNH CÔNG. Tăng bộ đếm thất bại ở đây sẽ khiến mọi
            // hư từ trông như đang hỏng.
            ->and($illustration->attempts)->toBe(0)
            ->and($illustration->failed_reason)->toBeNull();
    });

    it('tăng attempts khi Pixabay lỗi thật', function (): void {
        Http::fake(['pixabay.com/*' => Http::response('boom', 500)]);

        (new ResolveWordIllustration($this->word->id))->handle(app(IllustrationSelector::class));

        $illustration = DictionaryWordIllustration::where('word_id', $this->word->id)->firstOrFail();

        expect($illustration->status)->toBe(DictionaryWordIllustration::STATUS_FAILED)
            ->and($illustration->attempts)->toBe(1)
            ->and($illustration->failed_reason)->toBe('http_500');
    });

    it('KHÔNG tăng attempts khi chạm 429', function (): void {
        // 429 là tín hiệu nhịp độ. Tính nó là thất bại thì một đợt chạm trần sẽ
        // đánh dấu `failed` hàng loạt từ hoàn toàn bình thường — và vì bản ghi
        // cache vĩnh viễn, sai đó không tự khỏi.
        Http::fake(['pixabay.com/*' => Http::response('slow down', 429, ['X-RateLimit-Reset' => '20'])]);

        (new ResolveWordIllustration($this->word->id))->handle(app(IllustrationSelector::class));

        $illustration = DictionaryWordIllustration::where('word_id', $this->word->id)->first();

        expect($illustration?->attempts ?? 0)->toBe(0)
            ->and($illustration?->status)->not->toBe(DictionaryWordIllustration::STATUS_FAILED);
    });

    it('thoát sớm và không gọi API khi đã có kết luận ở gate hiện tại', function (): void {
        DictionaryWordIllustration::create([
            'word_id' => $this->word->id,
            'status' => DictionaryWordIllustration::STATUS_NONE,
            'gate_version' => IllustrationSelector::GATE_VERSION,
        ]);

        Http::fake();

        (new ResolveWordIllustration($this->word->id))->handle(app(IllustrationSelector::class));

        Http::assertNothingSent();
    });

    it('giữ khóa unique tự hết hạn để từ không bị kẹt vĩnh viễn', function (): void {
        // Không có `uniqueFor`, một job biến mất giữa chừng sẽ để khóa nằm lại
        // mãi và từ đó KHÔNG BAO GIỜ xếp hàng lại được — hỏng im lặng, không
        // lỗi, không log. Đã gặp đúng ca này ở lớp làm giàu bằng Gemini.
        $job = new ResolveWordIllustration($this->word->id);

        expect($job->uniqueFor)->toBeGreaterThan(0)
            ->and($job->uniqueId())->toBe((string) $this->word->id);
    });
});
