<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Stats\StatsSummaryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StatsSummaryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::in(StatsSummaryService::RANGES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['range.in' => 'Khoảng thời gian không hợp lệ.'];
    }

    public function range(): string
    {
        return (string) ($this->validated('range') ?? 'week');
    }
}
