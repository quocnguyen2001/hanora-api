<?php

declare(strict_types=1);

use App\Services\Topic\TopicGloss;

/**
 * Cổng nghĩa quyết định thẻ học hiện chữ gì, nên nó là chốt chặn của D7 ("thẻ
 * không gắn nhãn nguồn AI"). Mỗi ca dưới đây là một dòng dữ liệu THẬT trong
 * `dictionary_words`, không phải ví dụ bịa.
 */
it('cắt chú thích đuôi và giữ lại nghĩa thật', function (string $raw, string $expected): void {
    expect(TopicGloss::teaching($raw))->toBe($expected);
})->with([
    'chú thích lượng từ' => ['giấc mơ (LT: 場|场[chang2],個|个[ge4])', 'giấc mơ'],
    'chú thích phân biệt' => ['bạn (ngôi thứ hai thông dụng, khác với kính trọng 您[nin2])', 'bạn'],
    'nghĩa sạch giữ nguyên' => ['thích; ưa thích', 'thích; ưa thích'],
    'nhiều ngoặc liên tiếp' => ['tường (một) (hai)', 'tường'],
]);

it('loại mục siêu dữ liệu vì chúng không có nghĩa nào để dạy', function (string $raw): void {
    expect(TopicGloss::teaching($raw))->toBeNull();
})->with([
    'họ' => ['họ [Hua1]'],
    'biến thể' => ['biến thể của 碰[peng4]'],
    'biến thể cũ' => ['biến thể cũ của 笑[xiao4]'],
    'dùng trong' => ['dùng trong 嗎啡|吗啡[ma3 fei1]'],
    'viết tắt' => ['viết tắt của 的士[di1 shi4]'],
]);

it('loại nghĩa còn sót chữ Hán hoặc mã pinyin sau khi đã dọn', function (): void {
    // Tham chiếu nằm GIỮA câu nên không cắt được bằng luật ngoặc đuôi.
    expect(TopicGloss::teaching('xem 您[nin2] để biết thêm'))->toBeNull()
        ->and(TopicGloss::teaching('nghĩa là 爱 trong tiếng Trung'))->toBeNull();
});

it('không nhầm số trong nghĩa hợp lệ với mã pinyin', function (): void {
    // `[a-z]+[0-9]` chứ không phải `\d`: nghĩa thật có thể chứa số.
    expect(TopicGloss::teaching('thứ 3; ngày 3'))->toBe('thứ 3; ngày 3')
        ->and(TopicGloss::teaching('100 năm'))->toBe('100 năm');
});

/**
 * Đây là ca quan trọng nhất của cả file.
 *
 * CVDICT xếp một mục siêu dữ liệu lên ĐẦU cho những chữ giản thể vốn là biến
 * thể của chữ phồn thể. Chỉ đọc index 0 sẽ đánh rơi `家` khỏi chủ đề "gia đình"
 * và `笑` khỏi chủ đề cảm xúc — đúng những từ mà chủ đề đó tồn tại để dạy.
 */
it('quét cả mảng để tìm nghĩa dùng được đầu tiên, không chỉ index 0', function (array $glosses, string $expected): void {
    expect(TopicGloss::teachingFrom($glosses))->toBe($expected);
})->with([
    '家' => [['dùng trong 傢伙|家伙[jia1 huo5] và 傢俱|家俱[jia1 ju4]', 'nhà', 'gia đình'], 'nhà'],
    '笑' => [['biến thể cũ của 笑[xiao4]', 'cười; mỉm cười', 'cười nhạo'], 'cười; mỉm cười'],
    '岁' => [['biến thể của 歲|岁[sui4], năm', 'tuổi', 'năm'], 'tuổi'],
    '墙' => [['biến thể của 牆|墙[qiang2], tường', 'tường (LT: 面[mian4], 堵[du3])', 'chặn'], 'tường'],
]);

it('trả null khi cả mảng không có nghĩa nào dạy được', function (): void {
    expect(TopicGloss::teachingFrom(['biến thể của 碰[peng4]', 'họ [Peng4]']))->toBeNull()
        ->and(TopicGloss::teachingFrom([]))->toBeNull()
        ->and(TopicGloss::teachingFrom(null))->toBeNull();
});
