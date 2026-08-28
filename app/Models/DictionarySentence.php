<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Một câu tiếng Trung đã được phân tích, cache vĩnh viễn.
 *
 * @property int $id
 * @property string $zh_normalized
 * @property array<string, mixed>|null $payload
 * @property string|null $model
 * @property int $prompt_version
 * @property int $attempts
 * @property Carbon|null $generated_at
 */
final class DictionarySentence extends Model
{
    /** Quá số này thì thôi, câu này phân tích không được. */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = ['zh_normalized', 'payload', 'model', 'prompt_version', 'generated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'generated_at' => 'datetime'];
    }
}
