<?php

declare(strict_types=1);

namespace App\Services\Illustration;

use App\Models\DictionaryWord;

/**
 * Quyết định một từ có nên có ảnh minh hoạ không, và nếu có thì là ảnh nào.
 *
 * **Phần khó không phải là gọi Pixabay — mà là biết KHI NÀO KHÔNG được hiện
 * ảnh.** Pixabay luôn trả một ảnh trông có vẻ hợp lý, kể cả cho hư từ: `的` ra
 * ảnh bong bóng xà phòng, `非常` ra Taj Mahal. Người học nhớ sai một từ vì ảnh
 * minh hoạ còn tệ hơn hẳn việc không có ảnh nào — mà không-có-ảnh thì màn chi
 * tiết đã có sẵn khung placeholder và trông vẫn bình thường.
 *
 * Nguyên tắc: NGHI NGỜ THÌ ĐÓNG CỔNG.
 */
final class IllustrationSelector
{
    /**
     * Tăng khi đổi ngưỡng hoặc luật khớp.
     *
     * Bản ghi `none` được cache VĨNH VIỄN, nên không có số này thì mọi lần siết
     * cổng đều chỉ áp dụng cho từ chưa ai mở — còn hàng chục nghìn từ đã bị từ
     * chối bởi luật cũ sẽ nằm lại mãi.
     */
    public const GATE_VERSION = 1;

    /** Gloss dài hơn số từ này không bao giờ là một tag Pixabay. */
    private const MAX_GLOSS_WORDS = 3;

    public function __construct(private readonly PixabayClient $client) {}

    /**
     * Chọn ảnh cho một từ.
     *
     * Hai nhánh ngôn ngữ, vai trò KHÁC NHAU:
     *
     * - `zh` **chọn** ảnh. Tra bằng chữ Hán cho ảnh hợp văn hoá hơn hẳn: `火车`
     *   ra tàu hoả Trung Quốc, `银行` ra thẻ ngân hàng — trong khi `bank` tiếng
     *   Anh ra ghế đá công viên.
     * - `en` chỉ **xác nhận** rằng khái niệm này chụp ảnh được. Nó không bao giờ
     *   chọn ảnh.
     *
     * Vì sao phải có nhánh thứ hai: tra một mình `zh` **lọt `可能`**. Đo thật
     * 2026-08-29 — totalHits=500, 3/5 tag khớp nguyên token, ảnh đầu là một con
     * chim (`开普敦的可能莺`, Cape May Warbler). Tag tiếng Trung của Pixabay
     * dịch máy, nên "May" (tháng Năm) thành `可能`. Nhánh tiếng Anh đóng đúng ca
     * đó: `possible` chỉ có 67 totalHits.
     *
     * Đây cùng loại mơ hồ đồng âm mà nhánh Hán-Việt của dự án đã phải xử lý.
     */
    public function select(DictionaryWord $word): IllustrationSelection
    {
        $zh = $this->client->search($word->simplified, 'zh');

        if ($zh->throttled) {
            return IllustrationSelection::throttled($zh->retryAfter);
        }

        if (! $zh->successful) {
            return IllustrationSelection::failed($zh->reason ?? 'api_error');
        }

        $hit = $this->firstMatchingHit($zh, $word->simplified);

        /*
         * Cổng `zh` đóng thì DỪNG LUÔN, không tra tiếng Anh.
         *
         * Hư từ chiếm phần lớn số lần đóng cổng, nên thoát sớm ở đây tiết kiệm
         * đúng một request cho đúng nhóm từ hay bị mở nhất.
         */
        if ($hit === null) {
            return IllustrationSelection::none();
        }

        $gloss = $this->englishGloss($word);

        // Không có gloss đối chiếu được thì không xác nhận được. Bảo thủ có chủ
        // đích: không xác nhận được thì không hiện ảnh.
        if ($gloss === null) {
            return IllustrationSelection::none();
        }

        $en = $this->client->search($gloss, 'en');

        if ($en->throttled) {
            return IllustrationSelection::throttled($en->retryAfter);
        }

        if (! $en->successful) {
            return IllustrationSelection::failed($en->reason ?? 'api_error');
        }

        if ($this->firstMatchingHit($en, $gloss) === null) {
            return IllustrationSelection::none();
        }

        $candidate = $this->toCandidate($hit, $word->simplified);

        return $candidate === null
            ? IllustrationSelection::none()
            : IllustrationSelection::found($candidate);
    }

    /**
     * Hit đầu tiên thực sự mang tag khớp, hoặc `null` khi cổng đóng.
     *
     * Trả hit KHỚP chứ không phải `hits[0]`: ảnh được chọn phải là ảnh có tag
     * đúng, không phải ảnh đứng đầu một danh sách mà đa số khớp.
     *
     * @return array<string, mixed>|null
     */
    private function firstMatchingHit(PixabayResult $result, string $query): ?array
    {
        if ($result->totalHits < (int) config('services.pixabay.min_total_hits', 200)) {
            return null;
        }

        $matching = array_values(array_filter(
            $result->hits,
            fn (array $hit): bool => $this->tagsContain($hit, $query),
        ));

        if (count($matching) < (int) config('services.pixabay.min_tag_matches', 3)) {
            return null;
        }

        return $matching[0];
    }

    /**
     * Tag của một hit có chứa ĐÚNG NGUYÊN token truy vấn không.
     *
     * **KHÔNG BAO GIỜ dùng `str_contains` ở đây.** Tag Pixabay là chuỗi ngăn
     * cách bởi dấu phẩy, và một chữ Hán đơn nằm lọt trong hầu hết tag ghép:
     * `的` khớp chuỗi con với gần như mọi tag tiếng Trung, nên cổng chuỗi con
     * luôn trả true và hoàn toàn vô dụng. Đây là lỗi dễ mắc nhất của lớp này —
     * `IllustrationSelectorTest` có ca hồi quy khoá nó lại.
     *
     * @param  array<string, mixed>  $hit
     */
    private function tagsContain(array $hit, string $query): bool
    {
        $raw = $hit['tags'] ?? null;

        if (! is_string($raw)) {
            return false;
        }

        $tags = array_map(
            static fn (string $tag): string => mb_strtolower(trim($tag)),
            explode(',', $raw),
        );

        return in_array(mb_strtolower($query), $tags, true);
    }

    /**
     * Gloss tiếng Anh dùng để đối chiếu, hoặc `null` khi không có gloss dùng được.
     *
     * Ba thứ phải gỡ, cả ba đều là quy ước CC-CEDICT và không bao giờ xuất hiện
     * trong tag Pixabay:
     *
     * 1. Nhiều nghĩa gộp trong MỘT chuỗi, ngăn bằng dấu chấm phẩy. `医生` là
     *    `"doctor; medical practitioner"` — tra nguyên chuỗi đó trả 0 hit và
     *    đóng cổng oan một trong những từ cụ thể nhất có thể. Lấy nghĩa đầu.
     * 2. Chú thích trong ngoặc: `"cat (CL:隻|只[zhi1])"` → `cat`.
     * 3. Tiền tố `to ` đánh dấu động từ.
     *
     * Gloss còn lại dài hơn `MAX_GLOSS_WORDS` từ thì đóng cổng luôn thay vì tốn
     * một request để nhận 0 hit.
     */
    private function englishGloss(DictionaryWord $word): ?string
    {
        $first = $word->definitions_en[0] ?? null;

        if (! is_string($first)) {
            return null;
        }

        // Nghĩa ĐẦU TIÊN trong chuỗi nhiều nghĩa.
        $gloss = explode(';', $first)[0];
        // Bỏ chú thích trong ngoặc: "cat (CL:隻|只[zhi1])" → "cat".
        $gloss = (string) preg_replace('/\([^)]*\)/', ' ', $gloss);
        $gloss = trim((string) preg_replace('/\s+/', ' ', $gloss));
        $gloss = (string) preg_replace('/^to\s+/i', '', $gloss);
        $gloss = trim($gloss);

        if ($gloss === '' || str_word_count($gloss) > self::MAX_GLOSS_WORDS) {
            return null;
        }

        return $gloss;
    }

    /**
     * Dựng candidate từ một hit, suy ra URL ổn định.
     *
     * @param  array<string, mixed>  $hit
     */
    private function toCandidate(array $hit, string $matchedQuery): ?IllustrationCandidate
    {
        $preview = $hit['previewURL'] ?? null;
        $pageUrl = $hit['pageURL'] ?? null;

        if (! is_string($preview) || ! is_string($pageUrl)) {
            return null;
        }

        $imageUrl = self::widenPreviewUrl($preview);

        if ($imageUrl === null) {
            return null;
        }

        $author = isset($hit['user']) && is_string($hit['user']) ? $hit['user'] : null;
        $authorId = isset($hit['user_id']) ? (int) $hit['user_id'] : null;

        return new IllustrationCandidate(
            sourceId: (int) ($hit['id'] ?? 0),
            imageUrl: $imageUrl,
            previewUrl: $preview,
            pageUrl: $pageUrl,
            author: $author,
            // Hình dạng URL hồ sơ có trong docs Pixabay.
            authorUrl: $author !== null && $authorId !== null
                ? "https://pixabay.com/users/{$author}-{$authorId}/"
                : null,
            width: isset($hit['webformatWidth']) ? (int) $hit['webformatWidth'] : null,
            height: isset($hit['webformatHeight']) ? (int) $hit['webformatHeight'] : null,
            matchedQuery: $matchedQuery,
        );
    }

    /**
     * `..._150.jpg` trên CDN → `..._640.jpg`.
     *
     * **KHÔNG dùng `webformatURL`**: docs Pixabay ghi rõ "URL valid for 24
     * hours", nên lưu nó là cache một thứ tự huỷ sau một ngày. `previewURL` nằm
     * trên `cdn.pixabay.com` và ổn định.
     *
     * Việc đổi `_150` sang `_640` KHÔNG có trong docs — nó là hành vi đo được
     * (2026-08-29: trả 200, 88 KB). `_340` trả 403, nên tuyệt đối không suy ra
     * size nào khác. Đuôi không đúng dạng thì trả `null` chứ không đoán.
     */
    private static function widenPreviewUrl(string $previewUrl): ?string
    {
        if (! str_ends_with($previewUrl, '_150.jpg')) {
            return null;
        }

        return substr($previewUrl, 0, -mb_strlen('_150.jpg')).'_640.jpg';
    }
}
