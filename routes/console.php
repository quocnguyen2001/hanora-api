<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * Token Sanctum có hạn 90 ngày (D11), nhưng hết hạn chỉ khiến token bị từ chối
 * — dòng dữ liệu vẫn nằm lại trong `personal_access_tokens` mãi mãi. Dọn định
 * kỳ để bảng không phình theo số lần đăng nhập của toàn bộ người dùng.
 *
 * Dọn token quá hạn thêm 7 ngày, không phải đúng mốc hết hạn: giữ lại một cửa
 * sổ ngắn để còn điều tra được khi có báo cáo truy cập bất thường.
 *
 * Cần một scheduler đang chạy trên production (P20).
 */
Schedule::command('sanctum:prune-expired --hours=168')
    ->daily()
    ->description('Dọn token Sanctum đã quá hạn hơn 7 ngày');
