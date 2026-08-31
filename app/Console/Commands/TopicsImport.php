<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use App\Models\Topic;
use App\Services\Topic\TopicCatalog;
use App\Services\Topic\TopicPrompt;
use App\Services\Topic\TopicWordResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Nạp `database/data/topics/*.json` vào `topics` + `topic_words`.
 *
 * TẤT ĐỊNH và KHÔNG gọi AI. Chạy lại cho ra đúng cùng kết quả.
 *
 * Phải chạy SAU `cvdict:import` và `han-viet:import`: cổng lọc theo
 * `definitions_vi` và `han_viet_status`, nên chạy trước hai lệnh đó sẽ loại
 * sạch mọi từ và báo độ phủ 0%.
 */
final class TopicsImport extends Command
{
    protected $signature = 'topics:import
        {--topic= : Chỉ nạp một chủ đề (slug)}
        {--path= : Thư mục JSON (mặc định database/data/topics)}';

    protected $description = 'Nạp bộ từ chủ đề từ JSON vào database';

    /** Độ phủ giải được tối thiểu của MỘT chủ đề, đúng khuôn `cvdict:status`. */
    private const MIN_COVERAGE = 95.0;

    public function handle(TopicWordResolver $resolver): int
    {
        $dir = $this->option('path') ?: database_path('data/topics');

        if (! $this->checkPrerequisites()) {
            return self::FAILURE;
        }

        $files = $this->files((string) $dir);

        if ($files === null) {
            return self::FAILURE;
        }

        /*
         * Parse + validate TOÀN BỘ trước khi ghi một dòng nào.
         *
         * Bước 3 của kịch bản rà là người sửa tay file JSON (xoá một từ lạc chủ
         * đề), nên một dấu phẩy thừa là ca THẬT, không phải giả định. Ghi nửa
         * chừng rồi mới phát hiện file thứ 9 hỏng là để lại một database dở dang
         * mà không lệnh nào dọn được.
         */
        $parsed = [];

        foreach ($files as $path) {
            try {
                $parsed[] = $this->parse($path);
            } catch (RuntimeException $e) {
                $this->error("{$path}: {$e->getMessage()}");
                $this->error('KHÔNG dòng nào được ghi.');

                return self::FAILURE;
            }
        }

        try {
            $rows = DB::transaction(fn (): array => $this->write($parsed, $resolver));
        } catch (Throwable $e) {
            $this->error("Import thất bại, KHÔNG dòng nào được ghi: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->table(['Chủ đề', 'Trong file', 'Đã nạp', 'Độ phủ', 'Bị loại', 'Xoá dòng cũ'], $rows);
        $this->info('Đã nạp '.count($parsed).' chủ đề.');

        return self::SUCCESS;
    }

    /**
     * `topics:import` phụ thuộc CỨNG vào `cvdict:import` và `han-viet:import`.
     *
     * Kiểm ở đây rẻ hơn hẳn việc dựa vào người đọc runbook: đọc "cạnh
     * `cvdict:import`" mà đặt lệnh ngay TRƯỚC nó là cách đọc hợp lý, và trên
     * database sạch nó cho độ phủ 0% với một thông báo không nói được nguyên nhân.
     */
    private function checkPrerequisites(): bool
    {
        if (DictionaryWord::count() === 0) {
            $this->error('Từ điển rỗng — chạy `php artisan dictionary:import` trước.');

            return false;
        }

        if (DictionaryWord::whereNotNull('definitions_vi')->limit(1)->count() === 0) {
            $this->error('Chưa có nghĩa tiếng Việt — chạy `php artisan cvdict:import` TRƯỚC `topics:import`.');

            return false;
        }

        if (DictionaryWord::whereIn('han_viet_status', [DictionaryWord::STATUS_OK, DictionaryWord::STATUS_MANUAL])
            ->limit(1)->count() === 0) {
            $this->error('Chưa ghép âm Hán-Việt — chạy `php artisan han-viet:import` TRƯỚC `topics:import`.');

            return false;
        }

        return true;
    }

    /**
     * @return list<string>|null
     */
    private function files(string $dir): ?array
    {
        $slug = $this->option('topic');

        if (is_string($slug) && $slug !== '') {
            if (! TopicCatalog::has($slug)) {
                $this->error("Slug không có trong TopicCatalog: {$slug}");

                return null;
            }

            $path = "{$dir}/{$slug}.json";

            if (! is_file($path)) {
                $this->error("Không có file {$path}");

                return null;
            }

            return [$path];
        }

        $files = glob("{$dir}/*.json") ?: [];

        /*
         * 0 file KHÔNG được phép có nghĩa là "xoá hết".
         *
         * Thư mục JSON không vào được image là ca thật — `storage/app/` đã dẫm
         * đúng bẫy đó một lần và README ghi lại nó. Không có nhánh này thì một
         * image thiếu dữ liệu sẽ xoá sạch `topic_words` trên production rồi
         * exit 0, và lưới chủ đề thành một trang trắng không lỗi, không log.
         */
        if ($files === []) {
            $this->error("Không tìm thấy file JSON nào trong {$dir}.");
            $this->error('KHÔNG xoá dòng nào — bảng giữ nguyên. Kiểm tra thư mục có vào được image không.');

            return null;
        }

        return $files;
    }

    /**
     * @return array{slug: string, words: list<array<string, mixed>>}
     */
    private function parse(string $path): array
    {
        $raw = file_get_contents($path);
        $data = $raw === false ? null : json_decode($raw, true);

        if (! is_array($data)) {
            throw new RuntimeException('JSON không đọc được');
        }

        $slug = $data['slug'] ?? null;

        if (! is_string($slug) || ! TopicCatalog::has($slug)) {
            throw new RuntimeException('`slug` thiếu hoặc không có trong TopicCatalog');
        }

        if (basename($path, '.json') !== $slug) {
            throw new RuntimeException("tên file không khớp `slug` ({$slug})");
        }

        /*
         * Khoá theo `prompt_version`: một file sinh bởi prompt đã cũ có thể có
         * hình dạng đúng nhưng nội dung sinh theo luật khác. Từ chối nó ở đây rẻ
         * hơn nhiều so với phát hiện qua chất lượng thẻ học.
         */
        if (($data['prompt_version'] ?? null) !== TopicPrompt::VERSION) {
            throw new RuntimeException(
                sprintf('`prompt_version` là %s, cần %d — chạy lại `topics:generate`',
                    var_export($data['prompt_version'] ?? null, true), TopicPrompt::VERSION)
            );
        }

        $words = $data['words'] ?? null;

        if (! is_array($words) || $words === []) {
            throw new RuntimeException('`words` rỗng hoặc không phải mảng');
        }

        foreach ($words as $index => $word) {
            foreach (['zh', 'pinyin', 'rank', 'batch'] as $field) {
                if (! isset($word[$field])) {
                    throw new RuntimeException("words[{$index}] thiếu `{$field}`");
                }
            }
        }

        /** @var list<array<string, mixed>> $words */
        return ['slug' => $slug, 'words' => $words];
    }

    /**
     * @param  list<array{slug: string, words: list<array<string, mixed>>}>  $parsed
     * @return list<array<int, string>>
     */
    private function write(array $parsed, TopicWordResolver $resolver): array
    {
        $this->syncCatalog();

        $rows = [];

        foreach ($parsed as $file) {
            // `whereNull('user_id')`: chủ đề gốc. Thiếu nó thì một chủ đề TỰ
            // TẠO trùng slug có thể bị import ghi đè — dù validate đã chặn ca
            // đó, lệnh chạy trên production không được dựa vào một tầng khác.
            $topic = Topic::query()->whereNull('user_id')->where('slug', $file['slug'])->firstOrFail();

            $keep = [];
            $rejected = [];

            foreach ($file['words'] as $word) {
                $resolution = $resolver->resolveExact((string) $word['zh'], (string) $word['pinyin']);

                if (! $resolution->isAccepted()) {
                    $rejected[] = $word['zh'].'('.$resolution->rejection?->value.')';

                    continue;
                }

                $wordId = (int) $resolution->word->id;
                $keep[$wordId] = true;

                /*
                 * KHÔNG dùng `updateOrInsert`.
                 *
                 * Nó chạy `update()` mỗi lần dòng đã tồn tại, kể cả khi không có
                 * gì đổi — nên `created_at` bị ghi đè và mỗi lần import lại là
                 * ~1.280 lần UPDATE vô ích. Tiêu chí "chạy hai lần không đổi một
                 * dòng nào" khi đó là sai, và test chỉ chiếu ba cột nên không
                 * thấy.
                 */
                $rank = (int) $word['rank'];
                $batch = (int) $word['batch'];

                $existing = DB::table('topic_words')
                    ->where('topic_id', $topic->id)->where('word_id', $wordId)
                    ->first(['rank', 'generated_batch']);

                if ($existing === null) {
                    DB::table('topic_words')->insert([
                        'topic_id' => $topic->id, 'word_id' => $wordId,
                        'rank' => $rank, 'generated_batch' => $batch,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } elseif ((int) $existing->rank !== $rank || (int) $existing->generated_batch !== $batch) {
                    DB::table('topic_words')
                        ->where('topic_id', $topic->id)->where('word_id', $wordId)
                        ->update(['rank' => $rank, 'generated_batch' => $batch, 'updated_at' => now()]);
                }
            }

            /*
             * Xoá dòng thừa CHỈ TRONG chủ đề này.
             *
             * Bản kế hoạch đầu xoá "dòng không còn trong JSON" trên toàn bảng,
             * nên chạy lệnh khi thư mục chỉ có một file — đúng kịch bản chạy thử
             * mà chính kế hoạch đó hướng dẫn — sẽ xoá sạch 15 chủ đề kia và
             * exit 0.
             */
            $deleted = DB::table('topic_words')
                ->where('topic_id', $topic->id)
                ->whereNotIn('word_id', array_keys($keep) ?: [0])
                ->delete();

            $total = count($file['words']);
            $loaded = count($keep);
            $coverage = $total === 0 ? 0.0 : $loaded / $total * 100;

            if ($coverage < self::MIN_COVERAGE) {
                throw new RuntimeException(sprintf(
                    'chủ đề %s độ phủ %.1f%% < %.0f%% (%d/%d giải được). Bị loại: %s',
                    $file['slug'], $coverage, self::MIN_COVERAGE, $loaded, $total,
                    implode(', ', array_slice($rejected, 0, 8))
                ));
            }

            $rows[] = [
                $file['slug'],
                (string) $total,
                (string) $loaded,
                sprintf('%.1f%%', $coverage),
                $rejected === [] ? '—' : implode(' ', array_slice($rejected, 0, 4)),
                $deleted > 0 ? (string) $deleted : '—',
            ];
        }

        return $rows;
    }

    /**
     * Đồng bộ bảng `topics` từ `TopicCatalog`.
     *
     * `TopicCatalog` là nguồn sự thật; bảng là bản chiếu. Không có bước này thì
     * đổi tên chủ đề trong code sẽ không bao giờ tới được database.
     */
    private function syncCatalog(): void
    {
        foreach (TopicCatalog::all() as $topic) {
            Topic::query()->updateOrCreate(
                ['slug' => $topic['slug'], 'user_id' => null],
                [
                    'name' => $topic['name'],
                    'emoji' => $topic['emoji'],
                    'sort_order' => $topic['sort_order'],
                    'prompt_term' => $topic['prompt_term'],
                    'status' => Topic::STATUS_READY,
                ]
            );
        }
    }
}
