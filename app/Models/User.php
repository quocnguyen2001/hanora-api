<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Ba cột chuỗi khai `@property` ở đây để larastan biết kiểu SAU khi cast: thiếu
 * chúng thì `last_goal_met_on` bị suy ra là `string` và mọi phép ngày trên nó
 * báo lỗi tĩnh.
 *
 * Và ba cột đó PHẢI có trong `#[Fillable]` bên dưới. Repo không bật
 * `preventSilentlyDiscardingAttributes()`, nên thiếu một cột trong danh sách ấy
 * thì `update()` LẶNG LẼ bỏ nó: câu UPDATE vẫn chạy, không cột nào đổi, không
 * exception, không log. Chuỗi của mọi người sẽ đứng yên ở 0 trên production
 * trong khi test vẫn xanh — nếu test khẳng định trên instance đang giữ trong bộ
 * nhớ thay vì trên `->fresh()`.
 *
 * @property int $current_streak
 * @property int $longest_streak
 * @property CarbonImmutable|null $last_goal_met_on
 */
#[Fillable(['name', 'email', 'password', 'current_streak', 'longest_streak', 'last_goal_met_on'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            /*
             * `immutable_date`, không phải `date`: `StreakService` làm phép
             * `$lastMet->addDay()` khi so ngày liền kề, và trên một Carbon KHẢ
             * BIẾN phép đó sửa luôn biến gốc — nhánh so sánh kế tiếp sẽ đọc một
             * ngày đã bị đẩy đi một hôm.
             */
            'last_goal_met_on' => 'immutable_date',
        ];
    }
}
