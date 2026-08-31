<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Jobs\TranslateWordExamples;
use App\Models\DictionaryExample;
use App\Models\DictionaryWord;
use App\Services\Dictionary\Examples\ExampleTranslationPrompt;
use Illuminate\Http\JsonResponse;

/**
 * Nghĩa tiếng Việt của câu ví dụ, dịch lười và cache vĩnh viễn.
 *
 * **Không bao giờ 5xx vì Gemini.** Màn chi tiết đã render xong câu Hán và bản
 * dịch tiếng Anh trước khi gọi endpoint này; một lớp dịch chết không được phép
 * biến thành lỗi trên màn hình. Cùng hợp đồng mà `DictionaryIllustrationController`
 * đang giữ.
 *
 * Tách khỏi `/dictionary/words/{id}` chứ KHÔNG nối vào đó, và đó là điểm mấu
 * chốt: response chi tiết từ cache `public` 24 giờ, nên nhét một trường điền
 * lười vào nó là đóng băng `null` trên CDN cho mọi từ chưa ai mở.
 */
final class DictionaryExampleTranslationController
{
    private const CACHE_SECONDS = 60 * 60 * 24;

    /** Bao lâu thì FE nên hỏi lại khi bản dịch đang được sinh. */
    private const RETRY_AFTER = 3;

    public function __invoke(DictionaryWord $word): JsonResponse
    {
        $examples = $word->examples()->limit(DictionaryExample::MAX_PER_WORD)->get();

        /*
         * `data` LUÔN là mảng, kể cả ở nhánh `pending` và `unavailable`.
         *
         * Một từ có thể dịch xong 2 câu rồi cạn lượt ở câu thứ ba. Trả `null`
         * cho cả lô khi đó là vứt đi hai bản dịch đã trả tiền để có — và buộc
         * FE thêm một nhánh kiểm `null` cho thứ nó luôn muốn duyệt như mảng.
         */
        $data = $examples
            ->filter(fn (DictionaryExample $example): bool => ExampleTranslationPrompt::isCurrent($example))
            ->map(fn (DictionaryExample $example): array => [
                'id' => $example->id,
                'translation_vi' => $example->translation_vi,
            ])
            ->values()
            ->all();

        // Từ không có câu ví dụ, hoặc mọi câu đã dịch xong: cả hai đều là kết
        // luận VĨNH VIỄN, nên để CDN giữ câu trả lời đó 24 giờ là đúng.
        if (count($data) === $examples->count()) {
            return $this->ready($data);
        }

        // Định nghĩa "còn cần dịch" nằm ở job và chỉ ở đó — xem `pending()`.
        $pending = TranslateWordExamples::pending($word);

        if ($pending->isEmpty()) {
            return $this->unavailable($data);
        }

        /*
         * Lớp AI tắt là trạng thái cấu hình đã biết, không phải sự cố đang diễn
         * ra — đừng xếp job vào hàng đợi để nó thoát ra ngay, và đừng bảo FE
         * poll một thứ sẽ không bao giờ tới.
         */
        $key = config('services.gemini.key');

        if (! is_string($key) || $key === '') {
            return $this->unavailable($data);
        }

        TranslateWordExamples::dispatch($word->id);

        return response()
            ->json(['data' => $data, 'meta' => ['status' => 'pending']], 202)
            ->header('Retry-After', (string) self::RETRY_AFTER)
            // `no-store`: trạng thái tạm thời không được CDN đóng băng 24 giờ.
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @param  list<array{id: int, translation_vi: string|null}>  $data
     */
    private function ready(array $data): JsonResponse
    {
        return response()
            ->json(['data' => $data, 'meta' => ['status' => 'ready']])
            ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDS);
    }

    /**
     * Cạn lượt thử, hoặc lớp AI đang tắt.
     *
     * 200 kèm phần đã dịch được, KHÔNG phải 404 hay 503: FE có đúng một đường
     * xử lý — "có bản dịch thì hiện, không thì thôi". Trả mã lỗi buộc nó thêm
     * một nhánh catch cho một tình huống không phải lỗi.
     *
     * `no-store` chứ không cache: cắm key vào hoặc `vi_attempts` được dọn là
     * câu trả lời đổi ngay, khác hẳn nhánh `ready`.
     *
     * @param  list<array{id: int, translation_vi: string|null}>  $data
     */
    private function unavailable(array $data): JsonResponse
    {
        return response()
            ->json(['data' => $data, 'meta' => ['status' => 'unavailable']])
            ->header('Cache-Control', 'no-store');
    }
}
