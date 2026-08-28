<?php

declare(strict_types=1);

use App\Services\Dictionary\Search\SearchWeakness;

/**
 * Bảng này là bộ truy vấn vàng đo trên DB thật 123.646 dòng ngày 2026-08-28.
 *
 * Nó KHÔNG phải ví dụ minh hoạ. Mỗi dòng là một quan sát, và cột cuối là câu trả
 * lời đúng mà luật phải cho ra. Đổi luật thì phải đo lại bảng, không phải sửa
 * kỳ vọng cho khớp luật mới.
 */
dataset('bộ truy vấn vàng', [
    // truy vấn,            rank, prec, total, cần AI?
    'xin chào → 你好' => ['xin chào', 6, 0, 7, false],
    'cảm ơn → 谢谢' => ['cảm ơn', 6, 0, 74, false],
    'con mèo → 猫' => ['con mèo', 6, 1, 73, false],
    'anh yêu em → 博爱 SAI' => ['anh yêu em', 6, 2, 3, true],
    'học sinh → 学生' => ['học sinh', 5, 6, 317, true],
    'bác sĩ → 博士 SAI' => ['bác sĩ', 5, 6, 99, true],
    'yêu → 要 SAI' => ['yêu', 5, 6, 831, true],
    'tôi muốn ăn cơm → rỗng' => ['tôi muốn ăn cơm', null, null, 0, true],
    'bệnh viện ở đâu → rỗng' => ['bệnh viện ở đâu', null, null, 0, true],
]);

it('quyết định đúng trên bộ truy vấn vàng', function (
    string $query, ?int $rank, ?int $precision, int $total, bool $weak
): void {
    expect(SearchWeakness::isWeak($rank, $precision, $total))->toBe($weak, $query);
})->with('bộ truy vấn vàng');

it('không bao giờ hỏi AI khi đã khớp chữ Hán hoặc pinyin', function (int $rank): void {
    // Rank 1-4 là bằng chứng không mơ hồ. Dán 学习 vào ô tìm kiếm mà còn gọi AI
    // là trả 3,5 giây cho một câu hỏi đã có đáp án.
    expect(SearchWeakness::isWeak($rank, 6, 50))->toBeFalse();
})->with([1, 2, 3, 4]);

it('vẫn hỏi AI khi tổng bằng 0, kể cả rank mạnh', function (): void {
    // Không có dòng nào thì rank của dòng đầu là vô nghĩa.
    expect(SearchWeakness::isWeak(1, 0, 0))->toBeTrue();
});

it('coi bậc 2 của nhánh nghĩa Việt là yếu', function (): void {
    // Ranh giới đặt ở 1 chứ không phải 2, và `anh yêu em` là lý do.
    expect(SearchWeakness::isWeak(6, 1, 10))->toBeFalse()
        ->and(SearchWeakness::isWeak(6, 2, 10))->toBeTrue();
});
