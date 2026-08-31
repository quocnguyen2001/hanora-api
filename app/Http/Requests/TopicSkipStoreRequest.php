<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TopicSkipStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `exists`: một `word_id` không có thật sẽ vi phạm khoá ngoại và
            // trả 500. Chặn ở đây cho ra 422 với thông báo đọc được.
            'word_id' => ['required', 'integer', 'exists:dictionary_words,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'word_id.required' => 'Thiếu từ cần bỏ qua.',
            'word_id.exists' => 'Từ không tồn tại.',
        ];
    }
}
