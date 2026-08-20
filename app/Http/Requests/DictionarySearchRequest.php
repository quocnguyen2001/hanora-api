<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bound cụ thể cho tìm kiếm.
 *
 * Không có FormRequest thì `?q=<8KB rác>` sẽ ép chạy trigram trên 120k dòng —
 * một request rẻ tiền khóa được cả database.
 */
final class DictionarySearchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:1', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('q')) {
            $this->merge(['q' => trim((string) $this->input('q'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required' => 'Nhập từ cần tra.',
            'q.max' => 'Từ khóa không được dài quá 64 ký tự.',
            'page.max' => 'Trang vượt quá giới hạn.',
        ];
    }

    public function searchTerm(): string
    {
        return (string) $this->validated('q');
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
