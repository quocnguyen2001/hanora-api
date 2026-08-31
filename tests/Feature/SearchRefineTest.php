<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * `refine=ai` — đường vòng THỦ CÔNG quanh `SearchWeakness`.
 *
 * Ca mà nó tồn tại để phục vụ: SQL trông MẠNH nhưng sai. `SearchAiLayerTest` đã
 * khoá nhánh ngược lại (SQL yếu thì AI tự chạy); file này khoá nhánh mà cổng tự
 * động cố tình không đi.
 *
 * `学习` là ca mạnh: khớp chữ Hán chính xác, `rank ≤ 4`. Không có `refine` thì nó
 * KHÔNG BAO GIỜ chạm tới Gemini — và đó chính là bất biến mà nửa đầu file này giữ.
 */
beforeEach(function (): void {
    seedSearchFixtures();
});

describe('refine ép AI chạy khi SQL trông mạnh', function (): void {
    it('gọi AI cho truy vấn mà cổng tự động đã bỏ qua', function (): void {
        aiReturns(['学生']);

        $response = searchApi('学习', null, refine: 'ai');

        $response->assertOk()->assertJsonPath('meta.source', 'ai');
        expect($response->json('data.0.simplified'))->toBe('学生');
        Http::assertSentCount(1);
    });

    it('KHÔNG gọi AI cho đúng truy vấn đó khi không có refine', function (): void {
        // Bất biến quan trọng nhất của phase này: thêm tham số không được đổi
        // hành vi của đường tra bình thường.
        Http::fake();

        searchApi('学习', null)->assertOk()->assertJsonPath('meta.source', 'sql');

        Http::assertNothingSent();
    });

    it('không gọi AI ở trang 2 dù có refine', function (): void {
        // Xếp hạng của AI là khái niệm của trang đầu. Bấm nút ở trang 3 cũng
        // không cho AI thứ gì để đóng góp.
        Http::fake();

        searchApi('học', 'vi', page: 2, refine: 'ai')->assertOk();

        Http::assertNothingSent();
    });

    it('từ chối giá trị refine lạ', function (): void {
        // Cùng luật với `mode=vn`: gõ nhầm phải 422, không im lặng rơi về mặc
        // định — client không bao giờ biết mình sai nếu nó trả 200.
        Http::fake();

        searchApi('学习', null, refine: 'xyz')->assertStatus(422);

        Http::assertNothingSent();
    });
});

describe('refine hỏng thì rơi về SQL', function (): void {
    it('giữ kết quả SQL và đặt no-store khi Gemini chết', function (): void {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $response = searchApi('学习', null, refine: 'ai');

        $response->assertOk()->assertJsonPath('meta.source', 'sql');
        expect($response->json('data'))->not->toBeEmpty();

        // Một sự cố 30 giây không được phép bị CDN đóng băng thành 24 giờ.
        $response->assertHeader('Cache-Control', 'no-store, private');
    });
});

describe('trần chi tiêu', function (): void {
    it('chặn lượt refine thứ 21 trong một giờ', function (): void {
        aiReturns(['学生']);

        for ($i = 0; $i < 20; $i++) {
            searchApi('学习', null, refine: 'ai')->assertOk();
        }

        searchApi('学习', null, refine: 'ai')->assertStatus(429);
    });

    it('KHÔNG đụng tới tra từ bình thường', function (): void {
        /*
         * Limiter gắn lên chính `/search` nên nó nhìn thấy cả lượt tra thường.
         * Thiếu `Limit::none()` thì 20 nhịp gõ là mất ô tìm kiếm một giờ — hỏng
         * nặng hơn hẳn thứ nó định chặn. Đừng bỏ test này.
         */
        Http::fake();

        for ($i = 0; $i < 30; $i++) {
            searchApi('学习', null)->assertOk();
        }
    });
});
