<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Một từ người dùng đã bấm "Đã biết rồi" ở màn học chủ đề.
 *
 * TOÀN CỤC, không theo chủ đề: "đã biết rồi" là phát biểu về cái từ. Không soft
 * delete — không bảng nào tham chiếu tới và không có lịch sử nào để giữ.
 *
 * @property int $id
 * @property int $user_id
 * @property int $word_id
 */
final class UserSkippedWord extends Model
{
    protected $guarded = [];
}
