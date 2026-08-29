<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReviewSession;
use App\Models\User;
use App\Services\Review\AnswerGrader;
use App\Services\Review\ReviewScore;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Chỉ dùng cho test ĐỌC (lịch sử phiên cần hàng chục bản ghi).
 *
 * Test GHI vẫn phải đi qua endpoint thật — đó là cách duy nhất kiểm được đường
 * ghi, và cũng là lý do `ReviewLog` cố tình không có factory.
 *
 * @extends Factory<ReviewSession>
 */
final class ReviewSessionFactory extends Factory
{
    protected $model = ReviewSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $planned = $this->faker->numberBetween(5, 20);
        $answered = $this->faker->numberBetween(1, $planned);
        $correct = $this->faker->numberBetween(0, $answered);

        $startedAt = Carbon::now()->subDays($this->faker->numberBetween(0, 60));

        $score = app(ReviewScore::class)->score($answered, $correct);

        return [
            'user_id' => User::factory(),
            'mode' => $this->faker->randomElement([AnswerGrader::MODE_MCQ, AnswerGrader::MODE_TYPING]),
            'source' => $this->faker->randomElement(ReviewSession::SOURCES),
            'planned_count' => $planned,
            'answered_count' => $answered,
            'correct_count' => $correct,
            'score' => $score,
            'grade' => app(ReviewScore::class)->grade($score, $answered),
            'started_at' => $startedAt,
            // LUÔN >= started_at: test `duration_seconds >= 0` phụ thuộc vào
            // điều này, và một factory sinh ra thời lượng âm sẽ làm test đó
            // xanh vì lý do sai.
            'finished_at' => $startedAt->copy()->addSeconds($this->faker->numberBetween(30, 900)),
        ];
    }

    /** Phiên đang mở: chưa chốt điểm, chưa xếp loại. */
    public function open(): self
    {
        return $this->state(fn (): array => [
            'score' => null,
            'grade' => null,
            'finished_at' => null,
        ]);
    }

    /** Phiên mở chưa trả lời câu nào — thứ mà `finishStale()` phải XOÁ. */
    public function empty(): self
    {
        return $this->open()->state(fn (): array => [
            'answered_count' => 0,
            'correct_count' => 0,
        ]);
    }
}
