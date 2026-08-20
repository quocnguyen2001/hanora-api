<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserWord;

final class UserWordPolicy
{
    public function delete(User $user, UserWord $userWord): bool
    {
        return $user->id === $userWord->user_id;
    }

    public function view(User $user, UserWord $userWord): bool
    {
        return $user->id === $userWord->user_id;
    }
}
