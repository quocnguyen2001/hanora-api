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

        /*
         * Phân tích câu: dài hơn diễn giải truy vấn vì đầu ra nhiều hơn hẳn
         * (tách từ + nghĩa đen + ghi chú ngữ pháp). Vẫn nằm trên đường request
         * vì đó là nội dung CHÍNH của trang, nhưng người dùng đã chủ động bấm
         * vào nên họ đang chờ có chủ đích — khác với `/search` gõ tới đâu chạy
         * tới đó.
         */
        'sentence_timeout' => (int) env('GEMINI_SENTENCE_TIMEOUT', 15),
    ],

    /*
     * Pixabay — ảnh minh hoạ trên màn chi tiết từ.
     *
     * `timeout`: 8 giây. Lời gọi này nằm trong HÀNG ĐỢI chứ không trên đường
     * request, nên rộng rãi hơn `search_timeout: 6` của Gemini được.
     *
     * `rpm`: trần thật của Pixabay là 100 request/60 giây, tính theo API KEY
     * chứ không theo IP. Đặt 60 để chừa biên: mỗi từ tốn HAI request (một nhánh
     * tiếng Trung, một nhánh tiếng Anh để đối chiếu), tức ~30 từ mỗi phút. Con
     * số ở đây chỉ là van giảm áp; nguồn sự thật vẫn là mã 429 trả về.
     *
     * Ba ngưỡng cổng chặn đo trên 16 từ thật ngày 2026-08-29 — bảng số liệu nằm
     * trong `plans/260829-0635-anh-minh-hoa-pixabay/plan.md`. Để ở config để
     * chỉnh được mà không phải sửa code, NHƯNG đổi chúng thì phải tăng
     * `IllustrationSelector::GATE_VERSION`, nếu không những bản ghi `none` sinh
     * bởi ngưỡng cũ sẽ nằm lại vĩnh viễn và không ai quét lại.
     */
    'pixabay' => [
        'key' => env('PIXABAY_API_KEY'),
        'timeout' => (int) env('PIXABAY_TIMEOUT', 8),
        'rpm' => (int) env('PIXABAY_RPM', 60),
        'min_total_hits' => (int) env('PIXABAY_MIN_TOTAL_HITS', 200),
        'min_tag_matches' => (int) env('PIXABAY_MIN_TAG_MATCHES', 3),
        'sample_size' => (int) env('PIXABAY_SAMPLE_SIZE', 5),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
