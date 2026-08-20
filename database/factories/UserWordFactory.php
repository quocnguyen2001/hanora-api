<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DictionaryWord;
use App\Models\User;
use App\Models\UserWord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserWord>
 */
final class UserWordFactory extends Factory
{
    protected $model = UserWord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'word_id' => DictionaryWord::query()->inRandomOrder()->value('id'),
            'status' => UserWord::STATUS_NEW,
        ];
    }
}
