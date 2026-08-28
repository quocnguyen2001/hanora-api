<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bound cho phân tích câu.
 *
 * Mỗi câu MỚI là một lời gọi Gemini tốn tiền và ~4 giây. Không có bound thì
 * `?zh=<đoạn văn 8KB>` vừa đốt quota vừa giữ một worker PHP-FPM suốt trần
 * timeout — một request rẻ tiền làm được cả hai.
 */
final class DictionarySentenceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * `regex` bắt buộc phải có chữ Hán. Không có nó thì endpoint này
             * thành một API dịch tổng quát: gõ tiếng Anh, tiếng Việt, hay chuỗi
             * rác đều được nhận và trả tiền cho Gemini phân tích.
             */
            'zh' => ['required', 'string', 'min:1', 'max:200', 'regex:/\p{Han}/u'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('zh')) {
            $this->merge(['zh' => trim((string) $this->input('zh'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zh.required' => 'Thiếu câu cần phân tích.',
            'zh.max' => 'Câu không được dài quá 200 ký tự.',
            'zh.regex' => 'Câu phải chứa chữ Hán.',
        ];
    }

    public function sentence(): string
    {
        return (string) $this->validated('zh');
    }
}
