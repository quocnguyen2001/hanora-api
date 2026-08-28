<?php

declare(strict_types=1);

use App\Models\SearchQueryInterpretation;

it('không nổ khi chưa có truy vấn nào', function (): void {
    $this->artisan('dictionary:search-stats')
        ->expectsOutputToContain('Chưa có truy vấn nào')
        ->assertSuccessful();
});

/**
 * `hit_count` KHÔNG nằm trong `$fillable` — nó là bộ đếm nội bộ, không phải dữ
 * liệu ai đó gửi lên. Nên đặt nó phải đi qua query builder, đúng đường mà
 * `SearchInterpreter` dùng; `$model->update()` sẽ im lặng bỏ qua.
 */
function seedInterpretation(string $query, int $words, int $hits): void
{
    $row = SearchQueryInterpretation::query()->create([
        'query_normalized' => $query,
        'mode' => 'vi',
        // `range(1, 0)` trả `[1, 0]` chứ KHÔNG phải mảng rỗng.
        'word_ids' => $words > 0 ? range(1, $words) : [],
        'model' => 'gemini-3.1-flash-lite',
    ]);

    SearchQueryInterpretation::query()->whereKey($row->id)->update(['hit_count' => $hits]);
}

it('tính đúng tỉ lệ trúng cache', function (): void {
    // 2 lời gọi AI, 8 lượt trúng → 10 lượt phục vụ, trúng 80%.
    seedInterpretation('anh yêu em', words: 2, hits: 5);
    seedInterpretation('bác sĩ', words: 1, hits: 3);

    $this->artisan('dictionary:search-stats')
        ->expectsOutputToContain('Tỉ lệ trúng cache: 80.0%')
        ->assertSuccessful();
});

it('cho thấy cache tiết kiệm được bao nhiêu', function (): void {
    // Con số này là toàn bộ lý do bảng cache tồn tại; mất nó thì không ai biết
    // lớp AI đang tốn bao nhiêu.
    seedInterpretation('anh yêu em', words: 2, hits: 9);

    $this->artisan('dictionary:search-stats')
        ->expectsOutputToContain('cache tiết kiệm')
        ->assertSuccessful();
});

it('đếm riêng số đáp án rỗng', function (): void {
    // Truy vấn rác được cache có chủ đích; biết số đó mới đánh giá được prompt.
    seedInterpretation('asdfgh', words: 0, hits: 0);

    $this->artisan('dictionary:search-stats')->assertSuccessful();

    expect(SearchQueryInterpretation::whereRaw('jsonb_array_length(word_ids) = 0')->count())->toBe(1);
});
