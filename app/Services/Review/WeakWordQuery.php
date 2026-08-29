<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Models\DictionaryWord;
use App\Models\User;
use App\Models\UserWord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Từ hay sai" — nguồn dùng chung cho phiên ôn `weak` và endpoint danh sách.
 *
 * Một chỗ định nghĩa thế nào là hay sai. Viết lại mệnh đề ở endpoint sẽ cho ra
 * hai danh sách khác nhau cho cùng một câu hỏi.
 */
final class WeakWordQuery
{
    /**
     * Sai ít nhất một lần.
     *
     * Ngưỡng 1 để danh sách không rỗng với người mới. Đổi ngưỡng là sửa đúng
     * hằng số này.
     */
    private const WRONG_THRESHOLD = 1;

    /**
     * Truy vấn nền, dùng lại nguyên vẹn ở `ReviewHistoryController`.
     *
     * ## Vì sao sắp bằng ALIAS chứ không `orderByRaw` trần
     *
     * `cursorPaginate()` dựng con trỏ từ danh sách `orders`, và nó LỌC BỎ mọi
     * order không có key `direction` — tức mọi `orderByRaw`. Cursor khi đó rút
     * về `id` một mình, và điều kiện trang sau thành `WHERE id < :last_id`
     * trong khi `ORDER BY` thật vẫn là biểu thức: trang 2 mất sạch những từ có
     * id lớn hơn dòng cuối trang 1. Không lỗi, không exception, chỉ thiếu.
     *
     * `selectRaw ... AS alias` + `orderBy(alias)` cho ra order CÓ `direction`,
     * nên vừa an toàn vừa để resource đọc thẳng `wrong_count`/`accuracy` mà
     * không tính lại.
     *
     * Dù vậy endpoint vẫn dùng phân trang offset chứ không cursor: khóa sắp xếp
     * ở đây BIẾN ĐỔI mỗi lần người dùng trả lời, ngược hẳn lý do keyset được
     * chọn cho kho từ (khóa `(created_at, id)` bất biến). Danh sách này bản
     * chất là top-N và ngắn.
     *
     * @return Builder<UserWord>
     */
    public function query(User $user): Builder
    {
        return UserWord::query()
            ->where('user_id', $user->id)
            ->whereRaw('review_count - correct_count >= ?', [self::WRONG_THRESHOLD])
            /*
             * Cùng bộ lọc âm Hán-Việt mà `ReviewSessionBuilder::dueWords()`
             * dùng: cả hai mode ôn đều xoay quanh âm đó, nên một từ thiếu nó
             * cho ra câu hỏi hỏng chứ không phải câu hỏi khó.
             */
            ->whereHas('word', fn (Builder $query): Builder => $query->whereIn('han_viet_status', [
                DictionaryWord::STATUS_OK,
                DictionaryWord::STATUS_MANUAL,
            ]))
            ->select('user_words.*')
            ->selectRaw('(review_count - correct_count) AS wrong_count')
            ->selectRaw(
                'CASE WHEN review_count = 0 THEN 0
                      ELSE round(correct_count::numeric / review_count * 100) END AS accuracy'
            )
            ->orderBy('wrong_count', 'desc')
            // Cùng số lần sai thì từ nhớ kém hơn lên trước.
            ->orderBy('accuracy', 'asc')
            ->orderBy('id', 'desc');
    }

    /**
     * @return Collection<int, UserWord>
     */
    public function forUser(User $user, int $limit): Collection
    {
        return $this->query($user)->with('word')->limit($limit)->get();
    }
}
