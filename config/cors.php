<?php

declare(strict_types=1);

/*
 * CORS chỉ tồn tại ở môi trường dev, khi Vite chạy trên cổng riêng.
 *
 * Ở production frontend được nginx phục vụ CÙNG ORIGIN với API (D12), nên
 * không có request cross-origin nào. `FRONTEND_URL` để trống ở production
 * → danh sách allowed_origins rỗng → không origin nào được phép.
 *
 * KHÔNG thêm '*' vào đây. Nếu một request production cần CORS thì thứ sai là
 * cấu hình nginx, không phải file này.
 */

$frontendOrigin = env('FRONTEND_URL');

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter([$frontendOrigin]),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
