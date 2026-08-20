<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * TrustProxies giới hạn đúng CIDR mạng Docker của nginx.
         *
         * Rate limit của P9 khóa theo IP. Sau nginx container, mọi request
         * trông như đến từ MỘT IP duy nhất → "5 lần/phút/IP" sẽ khóa toàn bộ
         * người dùng sau 5 lần thử của bất kỳ ai.
         *
         * Đặt `*` để "sửa" thì TỆ HƠN: kẻ tấn công đổi `X-Forwarded-For` mỗi
         * request là giới hạn biến mất hoàn toàn. Chỉ tin đúng dải mạng nội bộ
         * mà nginx nằm trong đó.
         */
        $middleware->trustProxies(
            at: (string) env('TRUSTED_PROXIES', '172.16.0.0/12'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Trần mặc định cho toàn bộ nhóm route API. Endpoint nào cần chặt hơn
        // thì siết thêm tại chỗ bằng `throttle:` riêng của route đó.
        $middleware->throttleApi('60,1');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
