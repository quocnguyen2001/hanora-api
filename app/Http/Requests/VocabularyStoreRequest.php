<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VocabularyStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'word_id' => ['required', 'integer', 'exists:dictionary_words,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'word_id.required' => 'Thiếu từ cần lưu.',
            'word_id.exists' => 'Từ này không có trong từ điển.',
        ];
    }
}
