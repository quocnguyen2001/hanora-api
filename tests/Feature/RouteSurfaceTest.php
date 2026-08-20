<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Khóa các quy ước chốt ở P1. Chúng là quy ước toàn API, nên một phase sau vi
 * phạm mà không ai để ý là chuyện dễ xảy ra — những test này làm nó gãy ngay.
 */
it('chỉ mở đúng một route công khai', function (): void {
    $public = collect(Route::getRoutes())
        ->reject(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->uri())
        ->unique()
        ->sort()
        ->values()
        ->all();

    // Bốn endpoint auth công khai là ngoại lệ đã khai báo của D8. Không thêm
    // gì vào danh sách này mà không có lý do ghi lại được.
    expect($public)->toBe([
        'api/auth/forgot-password',
        'api/auth/login',
        'api/auth/register',
        'api/auth/reset-password',
        'api/health',
    ]);
});

it('siết throttle chặt hơn mặc định trên các endpoint auth công khai', function (): void {
    // Đây là bề mặt brute-force. Trần mặc định 60/phút quá rộng cho chúng.
    //
    // Bắt buộc là limiter CÓ TÊN: throttle inline dùng chung khóa cache với
    // `throttle:60,1` của nhóm `api`, mỗi request bị đếm hai lần, và trần thực
    // tế chỉ còn một nửa con số khai báo.
    $expected = [
        'api/auth/register' => 'throttle:auth-register',
        'api/auth/login' => 'throttle:auth-login',
        'api/auth/forgot-password' => 'throttle:auth-forgot-password',
        'api/auth/reset-password' => 'throttle:auth-reset-password',
    ];

    foreach ($expected as $uri => $middleware) {
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === $uri);

        expect($route)->not->toBeNull("thiếu route {$uri}")
            ->and($route->gatherMiddleware())->toContain($middleware);
    }
});

it('đặt mọi route API trong nhóm `api`', function (): void {
    // Nhóm `api` là nơi `throttleApi('60,1')` được gắn (bootstrap/app.php).
    // Một route `api/*` nằm ngoài nhóm này là một endpoint không có trần request.
    $outside = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
        ->reject(fn ($route) => in_array('api', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->uri())
        ->all();

    expect($outside)->toBeEmpty();
});

it('không đăng ký route nào chạy session middleware', function (): void {
    // Nhóm `web` ghi một dòng `sessions` cho mỗi request ẩn danh. Một endpoint
    // công khai như thế là bảng phình vô hạn không ai chặn.
    $webRoutes = collect(Route::getRoutes())
        ->filter(fn ($route) => in_array('web', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->uri())
        ->all();

    expect($webRoutes)->toBeEmpty();
});

it('trả X-RateLimit header trên route công khai', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', 60);
});
