<?php

declare(strict_types=1);

namespace App\Services\Topic;

/**
 * Danh mục 16 chủ đề học từ vựng — NGUỒN SỰ THẬT.
 *
 * Chủ đề là quyết định sản phẩm, không phải đầu ra của model. Bảng `topics`
 * chỉ là bản chiếu của danh mục này, do `topics:import` đồng bộ; `TopicResource`
 * đọc `name`/`emoji` từ đây chứ không từ bảng.
 *
 * **`slug` đóng băng vĩnh viễn.** Nó nằm trong URL công khai `/topics/{slug}`
 * và trong tên file `database/data/topics/{slug}.json` đã commit vào git.
 */
final class TopicCatalog
{
    /**
     * `prompt_term` TÁCH khỏi `name`, và đó không phải chi tiết trang trí.
     *
     * Số đo độ chính xác của lớp sinh (159/160 = 99,4% chữ Hán có thật) đo trên
     * tên NGẮN — `thức ăn`, `tình yêu`, `văn phòng`, `thiên nhiên`. Chuỗi đi vào
     * prompt là biến của phép đo đó, nên nó phải đúng bằng chuỗi đã đo. Tên ghép
     * (`Thức ăn & đồ uống`) chỉ để hiển thị: nó đọc rõ hơn trên lưới chủ đề,
     * nhưng chưa ai đo model phản ứng thế nào với dấu `&` trong prompt.
     *
     * Emoji tránh chuỗi ZWJ (`👨‍👩‍👧` = 5 codepoint) — cột là `varchar(8)`.
     *
     * @var list<array{slug: string, name: string, prompt_term: string, emoji: string}>
     */
    private const TOPICS = [
        ['slug' => 'tinh-yeu', 'name' => 'Tình yêu & cảm xúc', 'prompt_term' => 'tình yêu', 'emoji' => '💕'],
        ['slug' => 'gia-dinh', 'name' => 'Gia đình & con người', 'prompt_term' => 'gia đình', 'emoji' => '👪'],
        ['slug' => 'van-phong', 'name' => 'Văn phòng & công việc', 'prompt_term' => 'văn phòng', 'emoji' => '🏢'],
        ['slug' => 'hoc-tap', 'name' => 'Học tập & trường lớp', 'prompt_term' => 'học tập', 'emoji' => '📚'],
        ['slug' => 'thuc-an', 'name' => 'Thức ăn & đồ uống', 'prompt_term' => 'thức ăn', 'emoji' => '🍜'],
        ['slug' => 'thien-nhien', 'name' => 'Thiên nhiên & thời tiết', 'prompt_term' => 'thiên nhiên', 'emoji' => '🌤'],
        ['slug' => 'dong-thuc-vat', 'name' => 'Động vật & thực vật', 'prompt_term' => 'động vật và thực vật', 'emoji' => '🐾'],
        ['slug' => 'suc-khoe', 'name' => 'Cơ thể & sức khoẻ', 'prompt_term' => 'sức khoẻ', 'emoji' => '🩺'],
        ['slug' => 'du-lich', 'name' => 'Du lịch & giao thông', 'prompt_term' => 'du lịch', 'emoji' => '✈'],
        ['slug' => 'nha-cua', 'name' => 'Nhà cửa & đồ dùng', 'prompt_term' => 'nhà cửa', 'emoji' => '🏠'],
        ['slug' => 'mua-sam', 'name' => 'Mua sắm & tiền bạc', 'prompt_term' => 'mua sắm', 'emoji' => '🛒'],
        ['slug' => 'thoi-gian', 'name' => 'Thời gian & lịch', 'prompt_term' => 'thời gian', 'emoji' => '⏰'],
        ['slug' => 'giai-tri', 'name' => 'Thể thao & giải trí', 'prompt_term' => 'thể thao', 'emoji' => '⚽'],
        ['slug' => 'cong-nghe', 'name' => 'Công nghệ & truyền thông', 'prompt_term' => 'công nghệ', 'emoji' => '💻'],
        ['slug' => 'quan-ao', 'name' => 'Quần áo & ngoại hình', 'prompt_term' => 'quần áo', 'emoji' => '👕'],
        ['slug' => 'thanh-pho', 'name' => 'Thành phố & địa điểm', 'prompt_term' => 'thành phố', 'emoji' => '🏙'],
    ];

    /**
     * Mọi chủ đề, theo thứ tự hiển thị. `sort_order` là chỉ số trong mảng.
     *
     * @return list<array{slug: string, name: string, prompt_term: string, emoji: string, sort_order: int}>
     */
    public static function all(): array
    {
        $topics = [];

        foreach (self::TOPICS as $index => $topic) {
            $topics[] = [...$topic, 'sort_order' => $index];
        }

        return $topics;
    }

    /**
     * @return array{slug: string, name: string, prompt_term: string, emoji: string, sort_order: int}|null
     */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $topic) {
            if ($topic['slug'] === $slug) {
                return $topic;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_column(self::TOPICS, 'slug');
    }

    public static function has(string $slug): bool
    {
        return in_array($slug, self::slugs(), true);
    }
}
