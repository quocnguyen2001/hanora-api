<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * Gemini — lớp làm giàu từ điển (nghĩa theo từ loại, ví dụ song ngữ, phân
     * tích chữ, từ ghép, thành ngữ).
     *
     * `model`: chốt `3.1-flash-lite` bằng số đo, không phải bằng bảng giá.
     *
     * **`gemini-2.5-flash-lite` KHÔNG dùng được**, dù rẻ nhất ($0,10/$0,40).
     * Gọi thử 2026-08-28 trả 404 nguyên văn: "This model is no longer available
     * to new users." Nó vẫn nằm trong `ListModels` và vẫn có trên bảng giá —
     * đừng tin hai chỗ đó, chỉ một lời gọi thật mới trả lời được.
     *
     * Còn lại hai ứng viên, spike 20 từ HSK mỗi bên:
     *
     *   model              giá vào/ra   ví dụ sai   chữ lạ   toàn từ điển
     *   3.1-flash-lite     $0,25/$1,50      0/65      0/38          $141
     *   3.5-flash-lite     $0,30/$2,50      4/69      0/37          $223
     *
     * 3.1 rẻ hơn 37% và sạch hơn ở ví dụ. Chênh lệch chất lượng tiếng Việt giữa
     * hai bên không đọc ra được trên 20 mẫu.
     *
     * `rpm`: trần free tier của Google giờ ĐỘNG theo tài khoản — họ đã bỏ bảng
     * RPD cố định và chuyển sang AI Studio. Con số ở đây chỉ là van giảm áp cho
     * hàng đợi; nguồn sự thật là mã 429 trả về, và job phải bám `Retry-After`.
     *
     * `timeout`: 30 giây, không phải 5 như `HandwritingRecognizer`. Sinh một
     * khối nội dung dài hơn hẳn nhận dạng một nét chữ.
     */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
        'rpm' => (int) env('GEMINI_RPM', 10),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),

        /*
         * Trần riêng cho lớp diễn giải truy vấn, vì nó nằm TRÊN đường request
         * chứ không phải trong hàng đợi. Đo 2026-08-28: 5 lời gọi thật mất
         * 3,20s / 3,21s / 3,32s / 3,37s / 3,82s. 6 giây là biên ~1,6 lần trên
         * số đo đó, không phải một con số tròn cho đẹp.
         */
        'search_timeout' => (int) env('GEMINI_SEARCH_TIMEOUT', 6),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
