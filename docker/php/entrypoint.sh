#!/bin/sh
# Điểm vào của MỌI container dùng ảnh này: app, worker, scheduler.
#
# `config:cache` chạy Ở ĐÂY chứ không phải lúc build, và đó là điều kiện
# đúng/sai chứ không phải sở thích. Config cache là một mảng PHP đã CHỐT GIÁ
# TRỊ; khi file đó tồn tại, Laravel không đọc env nữa. Nướng nó vào ảnh nghĩa là
# mọi biến trong `env_file: [.env.production]` bị bỏ qua IM LẶNG — container vẫn
# khởi động, không lỗi, không log, chỉ là nó chạy bằng giá trị của máy build.
#
# Đo được, không phải suy đoán: với config cache nướng sẵn ở `DB_HOST=postgres`,
# một tiến trình chạy với biến môi trường `DB_HOST=zzz-runtime` vẫn đọc ra
# `postgres`.
#
# Một ảnh chạy được nhiều môi trường thì cache phải dựng lúc container khởi
# động. Đây cũng là lý do ảnh KHÔNG mang theo `.env` (xem `.dockerignore`).
set -e

php artisan config:cache

# `exec` để php-fpm / queue:work nhận đúng PID 1 và nhận được SIGTERM khi
# `docker stop` — không có nó thì mọi lần deploy đều kết thúc bằng kill sau 10s,
# cắt ngang job đang chạy dở.
exec "$@"
