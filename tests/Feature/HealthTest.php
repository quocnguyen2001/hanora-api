<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('trả trạng thái ok kèm trạng thái database và số dòng có nghĩa tiếng Việt', function (): void {
    // `definitions_vi` = 0 ở đây vì test chưa import: đó chính là tín hiệu mà
    // endpoint này tồn tại để phát ra — deploy đã chạy migration nhưng quên
    // `cvdict:import`, và cả tìm kiếm lẫn hiển thị nghĩa Việt sẽ hỏng im lặng.
    $this->getJson('/api/health')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'status' => 'ok',
                'db' => 'ok',
                'definitions_vi' => 0,
            ],
        ]);
});

it('báo db down mà vẫn trả 200 khi mất kết nối database', function (): void {
    // 200 là cố ý: 503 sẽ khiến load balancer rút node ra đúng lúc ta cần
    // chính endpoint này để chẩn đoán. Consumer đọc trường `db`.
    DB::shouldReceive('connection->getPdo')->andThrow(new PDOException('connection refused'));

    // `definitions_vi: null` chứ không phải 0 — không đọc được bảng là chuyện
    // khác với bảng chưa import, và consumer cần phân biệt được hai cái.
    $this->getJson('/api/health')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'status' => 'ok',
                'db' => 'down',
                'definitions_vi' => null,
            ],
        ]);
});

it('chạy test trên PostgreSQL chứ không phải SQLite', function (): void {
    // Bảo vệ quy ước của P1: lỗi đặc thù Postgres (generated column, extension)
    // phải lộ ra trong CI, không phải lúc deploy.
    expect(config('database.default'))->toBe('pgsql');
});

it('chốt múi giờ ứng dụng ở Asia/Ho_Chi_Minh', function (): void {
    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh');
});
