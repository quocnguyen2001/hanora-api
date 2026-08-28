<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\WordEnrichmentResource;
use App\Jobs\GenerateWordEnrichment;
use App\Models\DictionaryWord;
use App\Models\DictionaryWordEnrichment;
use App\Services\Dictionary\Enrichment\EnrichmentPrompt;
use Illuminate\Http\JsonResponse;

/**
 * Nội dung làm giàu của một từ, sinh nền và cache vĩnh viễn.
 *
 * **Không bao giờ 5xx vì Gemini.** Màn chi tiết đã render xong phần dữ liệu cứng
 * trước khi gọi endpoint này; một tính năng phụ chết không được phép biến thành
 * lỗi trên màn hình. Cùng hợp đồng mà `han_viet: null` và `examples: []` đang giữ.
 */
final class DictionaryEnrichmentController
{
    private const CACHE_SECONDS = 60 * 60 * 24;

    /** Bao lâu thì FE nên hỏi lại khi nội dung đang được sinh. */
    private const RETRY_AFTER = 3;

    public function __invoke(DictionaryWord $word): JsonResponse
    {
        $enrichment = $word->enrichment;

        if ($enrichment?->status === DictionaryWordEnrichment::STATUS_READY
            && $enrichment->prompt_version === EnrichmentPrompt::VERSION) {
            return response()
                ->json([
                    'data' => (new WordEnrichmentResource($enrichment))->resolve(),
                    'meta' => ['status' => DictionaryWordEnrichment::STATUS_READY],
                ])
                ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
        }

        /*
         * Cạn lượt thử thì trả 200 kèm `data: null`, KHÔNG phải 404 hay 503.
         *
         * FE có đúng MỘT đường xử lý — "có payload thì hiện, không thì ẩn khối"
         * — giống hệt cách nó đã xử lý `definitions_vi: null`. Trả mã lỗi buộc
         * nó thêm một nhánh catch cho một tình huống không phải lỗi.
         */
        if ($enrichment !== null
            && $enrichment->attempts >= DictionaryWordEnrichment::MAX_ATTEMPTS) {
            return $this->unavailable();
        }

        // Lớp AI tắt là trạng thái cấu hình đã biết, không phải sự cố đang diễn
        // ra — đừng xếp job vào hàng đợi để nó thất bại 3 lần rồi mới chịu im.
        $key = config('services.gemini.key');

        if (! is_string($key) || $key === '') {
            return $this->unavailable();
        }

        if ($enrichment === null) {
            $enrichment = DictionaryWordEnrichment::query()->create([
                'word_id' => $word->id,
                'status' => DictionaryWordEnrichment::STATUS_PENDING,
            ]);
        }

        GenerateWordEnrichment::dispatch($word->id);

        return response()
            ->json([
                'data' => null,
                'meta' => ['status' => DictionaryWordEnrichment::STATUS_PENDING],
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
