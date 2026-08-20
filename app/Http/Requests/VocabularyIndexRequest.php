<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\UserWord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class VocabularyIndexRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `null` = tab "Tất cả". Ba giá trị còn lại khớp bảng map ở UserWord.
            'status' => ['nullable', 'string', Rule::in(array_keys(UserWord::TAB_STATUSES))],
            'q' => ['nullable', 'string', 'max:64'],
            'cursor' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Bộ lọc không hợp lệ.',
            'q.max' => 'Từ khóa không được dài quá 64 ký tự.',
        ];
    }
}
