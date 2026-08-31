<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\User;
use App\Models\UserWord;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->words = DictionaryWord::factory()->count(20)->create();
});

function rebuild(?User $only = null): void
{
    $args = $only === null ? [] : ['--user' => $only->id];

    test()->artisan('hanora:streak-rebuild', $args)->assertSuccessful();
}

function columns(User $user): array
{
    $fresh = $user->fresh();

    return [
        'current' => $fresh->current_streak,
        'longest' => $fresh->longest_streak,
        'last' => $fresh->last_goal_met_on?->toDateString(),
    ];
}

describe('bất biến: rebuild ≡ giá trị đang lưu', function (): void {
    it('không đổi cột nào sau một chuỗi thao tác qua HTTP', function (): void {
        // Phép so QUAN TRỌNG NHẤT của lệnh này. Nó phải là "rebuild ≡ đường
        // ghi", không phải "rebuild ≡ rebuild" — cái sau tự thoả mãn và không
        // chứng minh gì.
        foreach ([2, 1, 0] as $daysAgo) {
            $day = vnDay($daysAgo);

            foreach (range(0, 4) as $i) {
                $word = UserWord::create([
                    'user_id' => $this->user->id,
                    'word_id' => $this->words[$daysAgo * 5 + $i]->id,
                ]);
                $word->forceFill(['created_at' => $day->setTime(10, 0)])->saveQuietly();
            }

            streak()->registerActivity($this->user, $day);
        }

        $before = columns($this->user);

        rebuild();

        expect(columns($this->user))->toBe($before)
            ->and($before['current'])->toBe(3);
    });

    it('phiên bỏ dở chốt lùi ngày cũng không tạo ra chênh lệch', function (): void {
        // Ca này từng là ngoại lệ đã biết của thiết kế: đường ghi bỏ qua ngày
        // quá khứ xa còn lệnh rebuild thì đếm. Nhánh đó đã bị gỡ vì guard
        // `$day <= last_goal_met_on` đủ bảo vệ chuỗi đang sống — và bài test này
        // là thứ khoá lại việc nó không quay về.
        finishedSessionOn($this->user, vnDay(5));
        streak()->registerActivity($this->user, vnDay(5));

        $before = columns($this->user);

        rebuild();

        expect(columns($this->user))->toBe($before);
    });
});

describe('idempotent', function (): void {
    it('chạy hai lần liên tiếp cho cùng kết quả', function (): void {
        foreach ([1, 0] as $daysAgo) {
            finishedSessionOn($this->user, vnDay($daysAgo));
        }

        rebuild();
        $first = columns($this->user);

        rebuild();

        expect(columns($this->user))->toBe($first);
    });

    it('không bao giờ hạ longest_streak', function (): void {
        // Kỷ lục là thành tích đã xảy ra. Một lệnh dựng lại không được phép xoá
        // nó, kể cả khi nguồn hiện tại không dựng lại được con số đó.
        $this->user->update(['longest_streak' => 42]);

        finishedSessionOn($this->user, vnDay());
        rebuild();

        expect($this->user->fresh()->longest_streak)->toBe(42);
    });
});

describe('dựng lại từ nguồn', function (): void {
    it('tính đúng chuỗi dài nhất và chuỗi đang giữ', function (): void {
        // Ba ngày liền (7,6,5 ngày trước) rồi nghỉ, sau đó hai ngày liền (1,0).
        foreach ([7, 6, 5, 1, 0] as $daysAgo) {
            finishedSessionOn($this->user, vnDay($daysAgo));
        }

        rebuild();

        expect(columns($this->user))->toBe([
            'current' => 2,
            'longest' => 3,
            'last' => vnDay()->toDateString(),
        ]);
    });

    it('bỏ qua ngày có phiên chốt nhưng không có lượt trả lời nào', function (): void {
        finishedSessionOn($this->user, vnDay(1), logs: 0);
        finishedSessionOn($this->user, vnDay());

        rebuild();

        expect(columns($this->user))->toBe([
            'current' => 1,
            'longest' => 1,
            'last' => vnDay()->toDateString(),
        ]);
    });

    it('đếm ngày đủ 5 từ, kể cả từ đã bị xoá khỏi kho', function (): void {
        foreach (range(0, 4) as $i) {
            $word = UserWord::create([
                'user_id' => $this->user->id,
                'word_id' => $this->words[$i]->id,
            ]);
            $word->forceFill(['created_at' => vnDay()->setTime(10, 0)])->saveQuietly();
        }

        UserWord::where('user_id', $this->user->id)->delete();

        rebuild();

        expect($this->user->fresh()->current_streak)->toBe(1);
    });

    it('để nguyên 0 cho người chưa làm gì', function (): void {
        rebuild();

        expect(columns($this->user))->toBe(['current' => 0, 'longest' => 0, 'last' => null]);
    });

    it('--user chỉ đụng đúng một người', function (): void {
        $other = User::factory()->create();
        finishedSessionOn($this->user, vnDay());
        $other->update(['current_streak' => 9, 'longest_streak' => 9]);

        rebuild($this->user);

        expect($this->user->fresh()->current_streak)->toBe(1)
            ->and($other->fresh()->current_streak)->toBe(9);
    });
});
