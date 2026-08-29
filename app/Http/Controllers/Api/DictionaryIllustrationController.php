<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\WordIllustrationResource;
use App\Jobs\ResolveWordIllustration;
use App\Models\DictionaryWord;
use App\Models\DictionaryWordIllustration;
use App\Services\Illustration\IllustrationSelector;
use Illuminate\Http\JsonResponse;

/**
 * Ảnh minh hoạ của một từ, resolve nền và cache vĩnh viễn.
 *
 * **Không bao giờ 5xx vì Pixabay.** Màn chi tiết đã render xong phần dữ liệu
 * cứng trước khi gọi endpoint này; một hình minh hoạ chết không được phép biến
 * thành lỗi trên màn hình. Cùng hợp đồng mà `DictionaryEnrichmentController`
 * đang giữ.
 */
final class DictionaryIllustrationController
{
    private const CACHE_SECONDS = 60 * 60 * 24;

    /** Bao lâu thì FE nên hỏi lại khi ảnh đang được tìm. */
    private const RETRY_AFTER = 3;

    public function __invoke(DictionaryWord $word): JsonResponse
    {
        $illustration = $word->illustration;
        $currentGate = $illustration?->gate_version === IllustrationSelector::GATE_VERSION;

        if ($illustration?->status === DictionaryWordIllustration::STATUS_READY && $currentGate) {
            return response()
                ->json([
                    'data' => (new WordIllustrationResource($illustration))->resolve(),
                    'meta' => ['status' => DictionaryWordIllustration::STATUS_READY],
                ])
                ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
        }

        /*
         * Cổng đã đóng: từ này đúng ra KHÔNG có ảnh.
         *
         * Cache `public` y hệt nhánh `ready` — khác hẳn `unavailable` bên dưới
         * — vì đây là kết luận VĨNH VIỄN, không phải trạng thái tạm. `的` sẽ
         * không bao giờ có ảnh, nên để CDN giữ câu trả lời đó 24 giờ là đúng.
         */
        if ($illustration?->status === DictionaryWordIllustration::STATUS_NONE && $currentGate) {
            return response()
                ->json([
                    'data' => null,
                    'meta' => ['status' => DictionaryWordIllustration::STATUS_NONE],
                ])
                ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
        }

        /*
         * Cạn lượt thử thì trả 200 kèm `data: null`, KHÔNG phải 404 hay 503.
         *
         * FE có đúng MỘT đường xử lý — "có payload thì hiện ảnh, không thì giữ
         * khung placeholder". Trả mã lỗi buộc nó thêm một nhánh catch cho một
         * tình huống không phải lỗi.
         */
        if ($illustration !== null
            && $illustration->attempts >= DictionaryWordIllustration::MAX_ATTEMPTS) {
            return $this->unavailable();
        }

        // Lớp ảnh tắt là trạng thái cấu hình đã biết, không phải sự cố đang diễn
        // ra — đừng xếp job vào hàng đợi để nó thất bại 3 lần rồi mới chịu im.
        $key = config('services.pixabay.key');

        if (! is_string($key) || $key === '') {
            return $this->unavailable();
        }

        if ($illustration === null) {
            DictionaryWordIllustration::query()->create([
                'word_id' => $word->id,
                'status' => DictionaryWordIllustration::STATUS_PENDING,
                'gate_version' => IllustrationSelector::GATE_VERSION,
            ]);
        }

        ResolveWordIllustration::dispatch($word->id);

        return response()
            ->json([
                'data' => null,
                'meta' => ['status' => DictionaryWordIllustration::STATUS_PENDING],
            ], 202)
            ->header('Retry-After', (string) self::RETRY_AFTER)
            // `no-store`: trạng thái tạm thời không được CDN đóng băng 24 giờ.
            ->header('Cache-Control', 'no-store');
    }

    private function unavailable(): JsonResponse
    {
        return response()
            ->json(['data' => null, 'meta' => ['status' => 'unavailable']])
            ->header('Cache-Control', 'no-store');
    }
}
