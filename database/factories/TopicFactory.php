<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Topic>
 */
final class TopicFactory extends Factory
{
    protected $model = Topic::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // `slug` unique nên phải sinh duy nhất; test nào cần slug thật của
            // `TopicCatalog` thì truyền vào rõ ràng.
            'slug' => 'chu-de-'.$this->faker->unique()->numberBetween(1, 999999),
            'name' => $this->faker->words(2, true),
            'emoji' => '📘',
            'sort_order' => $this->faker->numberBetween(0, 99),
        ];
    }
}
