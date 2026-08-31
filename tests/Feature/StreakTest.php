<?php

declare(strict_types=1);

use App\Models\DictionaryWord;
use App\Models\User;
use App\Models\UserWord;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->words = DictionaryWord::factory()->count(20)->create();
});

function snapshot(?User $as = null): array
{
    return test()->actingAs($as ?? test()->user, 'sanctum')
        ->getJson('/api/streak')
        ->json('data');
}

describe('luật đạt mục tiêu', function (): void {
    it('đạt khi thêm đủ 5 từ mới trong ngày', function (): void {
        addWords($this->user, 4, vnDay());
        streak()->registerActivity($this->user);
        expect($this->user->fresh()->current_streak)->toBe(0);

        addWords($this->user, 1, vnDay(), 4);
        streak()->registerActivity($this->user);

        expect($this->user->fresh()->current_streak)->toBe(1);
    });

    it('từ thứ 6 không tăng chuỗi thêm lần nữa', function (): void {
        addWords($this->user, 6, vnDay());

        streak()->registerActivity($this->user);
        streak()->registerActivity($this->user);

        expect($this->user->fresh()->current_streak)->toBe(1);
    });

    it('đạt khi chốt phiên ôn dù mới thêm 2 từ', function (): void {
        addWords($this->user, 2, vnDay());
        finishedSessionOn($this->user, vnDay());

        streak()->registerActivity($this->user);

        expect($this->user->fresh()->current_streak)->toBe(1);
    });

    it('KHÔNG đạt khi phiên chốt không có lượt trả lời nào — D4', function (): void {
        finishedSessionOn($this->user, vnDay(), logs: 0);

        streak()->registerActivity($this->user);

        expect($this->user->fresh()->current_streak)->toBe(0);
    });
});

describe('ghi ba cột thật sự — bẫy #[Fillable]', function (): void {
    it('đổi giá trị trên ->fresh(), không chỉ trên instance đang giữ', function (): void {
        // `#[Fillable]` thiếu cột nào thì `update()` LẶNG LẼ bỏ cột đó: câu
        // UPDATE vẫn chạy, không exception, không log. Khẳng định trên instance
        // trong bộ nhớ sẽ xanh trong khi database không đổi gì.
        addWords($this->user, 5, vnDay());

        streak()->registerActivity($this->user);

        $fresh = $this->user->fresh();

        expect($fresh->current_streak)->toBe(1)
            ->and($fresh->longest_streak)->toBe(1)
            ->and($fresh->last_goal_met_on->toDateString())->toBe(vnDay()->toDateString());
    });
});

describe('chuỗi liên tiếp', function (): void {
    it('nối chuỗi qua các ngày liền kề', function (): void {
        foreach ([2, 1, 0] as $i => $daysAgo) {
            addWords($this->user, 5, vnDay($daysAgo), $i * 5);
            streak()->registerActivity($this->user, vnDay($daysAgo));
        }

        expect($this->user->fresh()->current_streak)->toBe(3);
    });

    it('bỏ một ngày thì lần đạt kế tiếp về 1', function (): void {
        addWords($this->user, 5, vnDay(3));
        streak()->registerActivity($this->user, vnDay(3));

        addWords($this->user, 5, vnDay(), 5);
        streak()->registerActivity($this->user);

        expect($this->user->fresh()->current_streak)->toBe(1);
    });

    it('chưa học hôm nay nhưng đã học hôm qua thì chuỗi vẫn sống', function (): void {
        addWords($this->user, 5, vnDay(1));
        streak()->registerActivity($this->user, vnDay(1));

        $data = snapshot();

        expect($data['current'])->toBe(1)
            ->and($data['met_today'])->toBeFalse();
    });

    it('lần đạt cuối quá xa thì chuỗi đã chết, dù cột vẫn giữ số', function (): void {
        addWords($this->user, 5, vnDay(5));
        streak()->registerActivity($this->user, vnDay(5));

        expect($this->user->fresh()->current_streak)->toBe(1)
            ->and(snapshot()['current'])->toBe(0);
    });
});

describe('ranh giới ngày theo giờ VN', function (): void {
    it('23:50 và 00:10 giờ VN là hai ngày khác nhau', function (): void {
        $today = CarbonImmutable::now('Asia/Ho_Chi_Minh')->startOfDay();

        // 23:50 hôm qua + 00:10 hôm nay: gộp theo UTC sẽ nhét cả hai vào một
        // ngày và biến hai ngày liên tiếp thành một.
        addWords($this->user, 5, $today->subDay());
        $yesterdayLate = UserWord::where('user_id', $this->user->id)->get();
        foreach ($yesterdayLate as $w) {
            $w->forceFill(['created_at' => $today->subDay()->setTime(23, 50)])->saveQuietly();
        }
        streak()->registerActivity($this->user, $today->subDay());

        addWords($this->user, 5, $today, 5);
        foreach (UserWord::where('user_id', $this->user->id)->where('created_at', '>=', $today)->get() as $w) {
            $w->forceFill(['created_at' => $today->setTime(0, 10)])->saveQuietly();
        }
        streak()->registerActivity($this->user, $today);

        expect($this->user->fresh()->current_streak)->toBe(2);
    });
});

describe('xoá từ khỏi kho không viết lại lịch sử — red team H5', function (): void {
    it('xoá 10 từ sau khi đã đạt mục tiêu thì chuỗi giữ nguyên', function (): void {
        // Nửa CHUỖI của bài H5 cũ trong `StatsTest`. Kỳ vọng GIỮ NGUYÊN là chuỗi
        // còn sống — không hạ xuống 0 cho khớp luật mới.
        addWords($this->user, 5, vnDay());
        streak()->registerActivity($this->user);

        UserWord::where('user_id', $this->user->id)->delete();

        expect(snapshot()['current'])->toBe(1);
    });

    it('XOÁ TRƯỚC rồi mới tính: ngày đó vẫn đạt mục tiêu', function (): void {
        // Bài trên một mình là test GIẢ: nó ghi cột trước rồi mới xoá, mà
        // `liveStreak()` đọc cột — nên gỡ `withTrashed()` khỏi `wordsAddedOn()`
        // vẫn xanh. Bài này bắt đúng cái đó: chưa có gì trong cột, và số từ chỉ
        // đếm được nếu truy vấn nhìn thấy bản ghi đã soft-delete.
        addWords($this->user, 5, vnDay());
        UserWord::where('user_id', $this->user->id)->delete();

        streak()->registerActivity($this->user);

        expect($this->user->fresh()->current_streak)->toBe(1);
    });
});

describe('phiên bỏ dở chốt lùi ngày — D4', function (): void {
    it('ôn tối hôm qua rồi đóng tab, hôm nay chốt lại → hôm qua VẪN được tính', function (): void {
        // Đây là ca mà hook ở controller bỏ sót: `finishStale()` chốt phiên với
        // `finished_at` = lượt trả lời cuối, tức ngày hôm qua.
        finishedSessionOn($this->user, vnDay(1));

        streak()->registerActivity($this->user, vnDay(1));

        expect($this->user->fresh()->current_streak)->toBe(1)
            ->and($this->user->fresh()->last_goal_met_on->toDateString())
            ->toBe(vnDay(1)->toDateString());
    });

    it('phiên bỏ dở 5 ngày trước KHÔNG làm sống lại chuỗi đã đứt', function (): void {
        addWords($this->user, 5, vnDay());
        streak()->registerActivity($this->user);

        finishedSessionOn($this->user, vnDay(5));
        streak()->registerActivity($this->user, vnDay(5));

        $fresh = $this->user->fresh();

        expect($fresh->current_streak)->toBe(1)
            ->and($fresh->last_goal_met_on->toDateString())->toBe(vnDay()->toDateString());
    });

    it('ngày cũ CHƯA đếm được điền vào thì chuỗi nối lại đúng — C1', function (): void {
        // Bài trên lọt qua guard idempotent (`vnDay(5) <= hôm nay`) nên nó không
        // chạm nhánh ghi lùi thật. Bài này chạm: thứ Hai ôn xong nhưng phiên còn
        // mở, thứ Ba lưu đủ từ (ghi thứ Ba), rồi thứ Ba tối `finishStale()` chốt
        // phiên thứ Hai lùi ngày. Ba cột không biểu diễn được "có ngày cũ hơn
        // vừa điền vào", nên đường ghi phải tính lại từ nguồn.
        finishedSessionOn($this->user, vnDay(1));

        addWords($this->user, 5, vnDay(), 5);
        streak()->registerActivity($this->user);
        expect($this->user->fresh()->current_streak)->toBe(1);

        streak()->registerActivity($this->user, vnDay(1));

        expect($this->user->fresh()->current_streak)->toBe(2);
    });
});

describe('endpoint', function (): void {
    it('trả 401 khi không đăng nhập', function (): void {
        $this->getJson('/api/streak')->assertUnauthorized();
    });

    it('không cache ở service worker', function (): void {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/streak')
            ->assertHeader('Cache-Control', 'no-store, private');
    });

    it('không trả lịch nếu không hỏi', function (): void {
        expect(snapshot())->not->toHaveKey('calendar');
    });

    it('trả đúng 30 ô lịch khi ?calendar=1', function (): void {
        addWords($this->user, 5, vnDay(1));
        streak()->registerActivity($this->user, vnDay(1));

        $data = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/streak?calendar=1')
            ->json('data');

        expect($data['calendar'])->toHaveCount(30)
            ->and(collect($data['calendar'])->firstWhere('date', vnDay(1)->toDateString())['met'])->toBeTrue()
            ->and(collect($data['calendar'])->firstWhere('date', vnDay(3)->toDateString())['met'])->toBeFalse();
    });

    it('tiến độ hôm nay đếm đúng số từ đã thêm', function (): void {
        addWords($this->user, 3, vnDay());

        expect(snapshot()['today'])->toMatchArray([
            'words_added' => 3,
            'session_finished' => false,
            'words_goal' => 5,
        ]);
    });
});

describe('đường ghi trả trạng thái chuỗi', function (): void {
    it('POST /vocabulary trả streak, và advanced=true đúng ở từ thứ 5', function (): void {
        // Chip trên header nhích được nhờ đúng trường này — màn học chủ đề
        // không invalidate gì sau mỗi thẻ (ngân sách 60 request/phút).
        foreach (range(0, 3) as $i) {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/vocabulary', ['word_id' => $this->words[$i]->id]);

            expect($response->json('streak.advanced'))->toBeFalse();
        }

        $fifth = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/vocabulary', ['word_id' => $this->words[4]->id]);

        expect($fifth->json('streak.advanced'))->toBeTrue()
            ->and($fifth->json('streak.current'))->toBe(1)
            ->and($fifth->json('streak.met_today'))->toBeTrue();
    });

    it('lưu lại một từ đã xoá KHÔNG tính là từ mới', function (): void {
        addWords($this->user, 4, vnDay());

        $word = UserWord::where('user_id', $this->user->id)->first();
        $word->delete();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/vocabulary', ['word_id' => $word->word_id])
            ->assertOk();

        // Khôi phục không tạo dòng mới → vẫn 4 từ trong ngày, chưa đạt mục tiêu.
        expect($this->user->fresh()->current_streak)->toBe(0);
    });

    it('/auth/me mang chuỗi để chip có số khi ngoại tuyến', function (): void {
        addWords($this->user, 5, vnDay());
        streak()->registerActivity($this->user);

        $me = $this->actingAs($this->user, 'sanctum')->getJson('/api/auth/me')->json('data');

        expect($me['streak'])->toBe(['current' => 1, 'met_today' => true])
            ->and($me['streak']['current'])->toBe(snapshot()['current']);
    });
});
describe('C1 — repro', function (): void {
    it('ngày cũ chưa đếm bị nuốt khi đã có ngày mới hơn', function (): void {
        // Thứ Hai: ôn 8 thẻ rồi đóng tab (phiên còn mở → chốt sau).
        finishedSessionOn($this->user, vnDay(1));

        // Thứ Ba sáng: lưu 5 từ → chuỗi = 1, last_goal_met_on = thứ Ba.
        addWords($this->user, 5, vnDay(), 5);
        streak()->registerActivity($this->user);
        expect($this->user->fresh()->current_streak)->toBe(1);

        // Thứ Ba tối: mở /review → finishStale() chốt phiên thứ Hai lùi ngày.
        streak()->registerActivity($this->user, vnDay(1));

        // Nguồn nói HAI ngày liên tiếp đạt mục tiêu.
        expect($this->user->fresh()->current_streak)->toBe(2);
    });
});
