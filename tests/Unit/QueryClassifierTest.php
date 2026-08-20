<?php

declare(strict_types=1);

use App\Services\Dictionary\QueryClassifier;

beforeEach(function (): void {
    $this->classifier = new QueryClassifier;
});

it('nhận ra chuỗi có chữ Hán', function (string $query): void {
    expect($this->classifier->classify($query))->toBe(QueryClassifier::CLASS_HAN);
})->with([
    'chữ đơn' => ['学'],
    'từ ghép' => ['学习'],
    'phồn thể' => ['學習'],
    'lẫn latin' => ['学 abc'],
]);

it('nhận ra tiếng Việt qua dấu KHÔNG dùng chung với pinyin', function (string $query): void {
    expect($this->classifier->classify($query))->toBe(QueryClassifier::CLASS_VIETNAMESE);
})->with([
    'học tập' => ['học tập'],
    'ngân hàng' => ['ngân hàng'],
    'đông tây' => ['đông tây'],
    'chữ ư' => ['tư tưởng'],
    'chữ ê' => ['kê'],
]);

it('coi chuỗi latin còn lại là pinyin', function (string $query): void {
    expect($this->classifier->classify($query))->toBe(QueryClassifier::CLASS_PINYIN);
})->with([
    'pinyin dính' => ['xuexi'],
    'pinyin có dấu thanh' => ['xuéxí'],
    'định nghĩa tiếng Anh' => ['to study'],
    'không dấu mơ hồ' => ['hoc tap'],
]);

it('KHÔNG kết luận tiếng Việt từ dấu dùng chung với pinyin', function (): void {
    // `à á è é ì í ò ó ù ú` dùng chung codepoint với dấu thanh pinyin, nên gặp
    // chúng thì không kết luận được gì. `xuéxí` là pinyin, không phải tiếng Việt.
    expect($this->classifier->classify('xuéxí'))->toBe(QueryClassifier::CLASS_PINYIN)
        ->and($this->classifier->classify('mà'))->toBe(QueryClassifier::CLASS_PINYIN);
});

it('bật cờ mayBeVietnamese cho chuỗi latin không dấu', function (): void {
    // `hoc tap` mơ hồ giữa pinyin và Hán-Việt. Người Việt hay gõ không dấu nên
    // đây là ca thường gặp — phải chạy CẢ hai nhánh rồi để xếp hạng quyết định.
    expect($this->classifier->mayBeVietnamese('hoc tap'))->toBeTrue()
        ->and($this->classifier->mayBeVietnamese('học tập'))->toBeTrue()
        ->and($this->classifier->mayBeVietnamese('学习'))->toBeFalse();
});
