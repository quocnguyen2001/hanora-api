<?php

declare(strict_types=1);

/*
 * Cố tình để trống.
 *
 * Repo này chỉ phục vụ API. Bề mặt công khai duy nhất là `GET /api/health`
 * (routes/api.php), và `RouteSurfaceTest` khóa điều đó lại.
 *
 * Đừng thêm route vào đây: nhóm `web` chạy session middleware, nên mỗi request
 * ẩn danh sẽ ghi một dòng vào bảng `sessions` — bảng đó phình vô hạn từ một
 * endpoint không ai xác thực và không có throttle.
 */
