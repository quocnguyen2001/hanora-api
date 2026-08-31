<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\TopicStoreRequest;
use App\Http\Resources\TopicResource;
use App\Http\Resources\TopicWordResource;
use App\Jobs\GenerateUserTopic;
use App\Models\DictionaryWord;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Lưới chủ đề và bộ từ của một chủ đề.
 *
 * SQL thuần, không Gemini, không Pixabay, không job, không 202 — nội dung đã
 * nằm sẵn trong bảng từ `topics:import`. Đây là điểm khác biệt lớn nhất so với
 * ba lớp AI đang có, vốn phải dựng cả bộ máy trạng thái vì AI chạy lúc người
 * dùng đang chờ.
 */
final class TopicController
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        /*
         * MỘT truy vấn cho ba con số, không phải N+1 theo chủ đề.
         *
         * `processed_count` đếm DISTINCT trên HỢP của hai nguồn: từ đã lưu (còn
         * sống) và từ đã bỏ qua. Cộng hai bộ đếm riêng sẽ đếm hai lần những từ
         * nằm ở cả hai bảng — bỏ qua ở chủ đề A rồi lưu từ màn Tìm kiếm là
         * đường đi bình thường — và thẻ sẽ hiện `80/78`.
         *
         * `deleted_at is null` KHÔNG được quên: `user_words` dùng soft delete,
         * và một `join` của query builder bỏ qua `SoftDeletingScope` của
         * Eloquent. Thiếu nó thì `/topics` đếm cả từ người dùng đã xoá khỏi kho,
         * trong khi `/vocabulary/ids` (chạy qua Eloquent) thì không — hai màn
         * nói hai điều khác nhau về cùng một chủ đề.
         */
        $topics = Topic::query()
            // Chủ đề gốc + chủ đề của chính user. Quên scope ở đây là lộ TÊN
            // chủ đề riêng của người khác trong lưới.
            ->visibleTo($userId)
            ->select('topics.*')
            ->selectSub(
                DB::table('topic_words')->selectRaw('count(*)')
                    ->whereColumn('topic_words.topic_id', 'topics.id'),
                'word_count'
            )
            ->selectSub(
                DB::table('topic_words')
                    ->join('user_words', 'user_words.word_id', '=', 'topic_words.word_id')
                    ->selectRaw('count(*)')
                    ->whereColumn('topic_words.topic_id', 'topics.id')
                    ->where('user_words.user_id', $userId)
                    ->whereNull('user_words.deleted_at'),
                'learned_count'
            )
            ->selectSub(
                DB::table('topic_words')
                    ->selectRaw('count(distinct topic_words.word_id)')
                    ->whereColumn('topic_words.topic_id', 'topics.id')
                    ->where(function ($q) use ($userId): void {
                        $q->whereExists(fn ($sub) => $sub->from('user_words')
                            ->whereColumn('user_words.word_id', 'topic_words.word_id')
                            ->where('user_words.user_id', $userId)
                            ->whereNull('user_words.deleted_at'))
                            ->orWhereExists(fn ($sub) => $sub->from('user_skipped_words')
                                ->whereColumn('user_skipped_words.word_id', 'topic_words.word_id')
                                ->where('user_skipped_words.user_id', $userId));
                    }),
                'processed_count'
            )
            // Chủ đề gốc trước (sort_order 0-15), chủ đề tự tạo sau, mới nhất
            // lên đầu trong nhóm của nó.
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        // `private, no-store`: mang tiến độ theo user. Đây là dữ liệu KHÔNG được
        // service worker cache — đường rò giữa hai tài khoản trên cùng thiết bị.
        return response()->json(['data' => TopicResource::collection($topics)->resolve()])
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * CẢ BỘ từ của chủ đề — không cursor, không random phía server.
     *
     * Bộ từ nhỏ (35-114 từ/chủ đề, đo thật), nên client giữ được cả bộ và tự
     * lọc/bốc. Đúng nguyên tắc "KHÔNG có state phía server" mà
     * `ReviewSessionBuilder` đã chốt.
     */
    public function words(Request $request, string $slug): JsonResponse
    {
        /*
         * Scope là BẮT BUỘC ở đây, không phải phòng xa.
         *
         * Thiếu nó thì bất kỳ ai đoán được slug đều đọc trọn bộ từ của chủ đề
         * riêng người khác — và slug suy thẳng từ tên nên đoán được.
         */
        $topic = Topic::query()
            ->visibleTo($request->user()->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $words = DictionaryWord::query()
            ->join('topic_words', 'topic_words.word_id', '=', 'dictionary_words.id')
            ->where('topic_words.topic_id', $topic->id)
            ->orderBy('topic_words.rank')
            ->get(['dictionary_words.*', 'topic_words.rank as rank']);

        return response()->json([
            'data' => TopicWordResource::collection($words)->resolve(),
            /*
             * `word_count` đi kèm để app phát hiện bộ từ đã đổi.
             *
             * Response này cache 5 phút; lưới chủ đề thì `no-store`. Không có
             * con số này, sau khi maintainer mở rộng một chủ đề, thẻ sẽ báo "còn
             * 20 từ" trong khi màn học đọc bản cache cũ và báo "đã học hết".
             */
            'meta' => ['word_count' => $words->count()],
            /*
             * `private` cho chủ đề TỰ TẠO: bộ từ của một người không được nằm
             * trong cache dùng chung của thiết bị hay proxy. Chủ đề gốc vẫn
             * `public` như cũ — nó giống nhau cho mọi người.
             */
        ])->header(
            'Cache-Control',
            $topic->isCustom()
                ? 'private, no-store'
                : 'public, max-age=300, stale-while-revalidate=86400'
        );
    }

    /**
     * Tạo chủ đề mới và xếp job sinh từ.
     *
     * `202`, không `201`: chủ đề đã tồn tại nhưng CHƯA dùng được. Trả `201` ở
     * đây là nói với app rằng tài nguyên đã sẵn sàng, và app sẽ mở màn học của
     * một chủ đề không có từ nào.
     *
     * KHÔNG gọi Gemini trong request — chỉ dispatch. Sinh một bộ từ mất 16-25
     * giây.
     */
    public function store(TopicStoreRequest $request): JsonResponse
    {
        $name = trim((string) $request->validated('name'));

        $topic = Topic::create([
            'user_id' => $request->user()->id,
            'slug' => $request->slug(),
            'name' => $name,
            // Tên người dùng gõ CHÍNH LÀ prompt. Chủ đề gốc tách hai thứ này vì
            // số đo độ chính xác đo trên tên ngắn; ở đây không có tên nào khác.
            'prompt_term' => $name,
            'emoji' => (string) ($request->validated('emoji') ?? '📘'),
            // Sau 16 chủ đề gốc (sort_order 0-15).
            'sort_order' => 100,
            'status' => Topic::STATUS_GENERATING,
        ]);

        GenerateUserTopic::dispatch($topic->id);

        return response()->json(
            ['data' => new TopicResource($topic)],
            Response::HTTP_ACCEPTED
        )->header('Cache-Control', 'private, no-store');
    }

    /**
     * Xoá một chủ đề TỰ TẠO.
     *
     * Không có nó thì một chủ đề sinh ra tệ nằm lại vĩnh viễn và ăn một suất
     * trong trần 20 — người dùng hết đường lui. Sinh lại = xoá rồi tạo lại;
     * không có endpoint "generate lại" riêng cho cùng một kết quả.
     */
    public function destroy(Request $request, string $slug): Response
    {
        /*
         * `whereNotNull('user_id')` + khớp user: chủ đề GỐC trả 404, không phải
         * 403. 403 xác nhận nó tồn tại và thuộc về ai đó — đúng lập luận đã ghi
         * ở `VocabularyController::destroy()`.
         */
        $topic = Topic::query()
            ->whereNotNull('user_id')
            ->where('user_id', $request->user()->id)
            ->where('slug', $slug)
            ->first();

        if (! $topic instanceof Topic) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // `topic_words` cascade theo `topic_id`, nên xoá chủ đề là sạch.
        $topic->delete();

        return response()->noContent();
    }
}
