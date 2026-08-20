<?php

declare(strict_types=1);

namespace App\Services\Handwriting;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Nhận dạng chữ Hán viết tay.
 *
 * Proxy tới Google Input Tools. Chọn engine này sau spike R3 — xem
 * `plans/reports/spike-260821-0140-handwriting-engine.md`: 100% top-5 trên 60 ca
 * thử, so với 72% của HanziLookupJS (và chỉ 43% khi viết ẩu).
 *
 * **Endpoint KHÔNG chính thức và có thể ngừng bất cứ lúc nào.** Toàn bộ phụ
 * thuộc vào nó nằm gọn trong class này; đổi engine chỉ chạm một file. Khi nó
 * chết, bảng vẽ báo lỗi rõ ràng và người dùng vẫn gõ bằng bàn phím được — tính
 * năng này chưa bao giờ là đường duy nhất để trả lời.
 *
 * Proxy qua backend là bắt buộc vì CORS, và tiện thể giữ được throttle theo user.
 */
final class HandwritingRecognizer
{
    private const ENDPOINT = 'https://inputtools.google.com/request';

    private const MAX_CANDIDATES = 8;

    private const TIMEOUT_SECONDS = 5;

    /**
     * @param  array<int, array<int, array<int, float|int|string>>>  $strokes
     * @return list<string>
     */
    public function recognise(array $strokes, int $width, int $height): array
    {
        $ink = [];

        foreach ($strokes as $stroke) {
            $xs = [];
            $ys = [];
            $ts = [];

            foreach (array_values($stroke) as $index => $point) {
                $xs[] = (float) $point[0];
                $ys[] = (float) $point[1];
                // Google cần trục thời gian; khoảng cách đều là đủ.
                $ts[] = $index * 40;
            }

            $ink[] = [$xs, $ys, $ts];
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post(self::ENDPOINT.'?itc=zh-t-i0-handwrit&num='.self::MAX_CANDIDATES, [
                    'options' => 'enable_pre_space',
                    'requests' => [[
                        'writing_guide' => [
                            'writing_area_width' => $width,
                            'writing_area_height' => $height,
                        ],
                        'ink' => $ink,
                        'language' => 'zh_CN',
                    ]],
                ]);
        } catch (Throwable) {
            // Mạng hỏng hoặc endpoint chết — caller biến thành lỗi thân thiện.
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload[0] ?? null) !== 'SUCCESS') {
            return [];
        }

        $candidates = $payload[1][0][1] ?? [];

        if (! is_array($candidates)) {
            return [];
        }

        return array_values(array_slice(
            array_filter($candidates, is_string(...)),
            0,
            self::MAX_CANDIDATES,
        ));
    }
}
