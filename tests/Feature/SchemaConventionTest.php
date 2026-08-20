<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P1 quy ước: mọi cột thời gian là `timestamptz`.
 *
 * Không phải sở thích. `APP_TIMEZONE=Asia/Ho_Chi_Minh` khiến PHP ghi giờ +07
 * vào cột; nếu cột không mang múi giờ thì mọi so sánh phía SQL (`now()`,
 * gom nhóm theo ngày cho streak và `next_review_at` ở P14/P16) lệch 7 tiếng.
 * Đây chính là finding H6 của red team.
 */
it('đặt session timezone của Postgres khớp APP_TIMEZONE', function (): void {
    // Kiểu cột `timestamptz` KHÔNG tự đủ. Laravel gửi datetime xuống dưới dạng
    // chuỗi không kèm offset, và Postgres diễn giải nó theo TimeZone của
    // session. Session ở UTC + APP_TIMEZONE ở +07 = mọi mốc thời gian lệch 7
    // giờ về tương lai, im lặng.
    $sessionTimezone = DB::selectOne('show timezone')->TimeZone;

    expect($sessionTimezone)->toBe('Asia/Ho_Chi_Minh')
        ->and($sessionTimezone)->toBe(config('app.timezone'));
});

it('lưu và đọc lại đúng mốc thời gian, không lệch múi giờ', function (): void {
    // Hồi quy cho red team H6, kiểm bằng vòng đầy đủ chứ không chỉ kiểu cột.
    $user = User::factory()->create();

    $lechGiay = abs(
        (int) DB::selectOne(
            'select extract(epoch from (now() - created_at)) as lech from users where id = ?',
            [$user->id]
        )->lech
    );

    // Cùng một khoảnh khắc thì hiệu phải gần 0. Lệch 7 tiếng = 25200 giây.
    expect($lechGiay)->toBeLessThan(60);
});

it('không có cột timestamp nào thiếu múi giờ', function (): void {
    $offenders = DB::table('information_schema.columns')
        ->select('table_name', 'column_name', 'data_type')
        ->where('table_schema', 'public')
        ->where('data_type', 'timestamp without time zone')
        ->orderBy('table_name')
        ->orderBy('column_name')
        ->get()
        ->map(fn ($column) => "{$column->table_name}.{$column->column_name}")
        ->all();

    expect($offenders)->toBeEmpty();
});
