<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ReviewSession;
use App\Services\Review\AnswerGrader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewSessionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in([AnswerGrader::MODE_MCQ, AnswerGrader::MODE_TYPING])],

            /*
             * Nguồn thẻ: `due` theo lịch SRS, `weak` là những từ hay sai bất kể
             * lịch. Mặc định `due` — đó là vòng ôn bình thường.
             */
            'source' => ['nullable', Rule::in(ReviewSession::SOURCES)],

            // Trần 50: không giới hạn thì một request ép sinh distractor cho
            // hàng chục nghìn mục, mỗi mục là một truy vấn ngẫu nhiên.
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.required' => 'Thiếu chế độ ôn tập.',
            'mode.in' => 'Chế độ ôn tập không hợp lệ.',
            'source.in' => 'Nguồn từ ôn tập không hợp lệ.',
            'limit.between' => 'Số từ mỗi phiên phải từ 1 đến 50.',
        ];
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
    }

    public function limitValue(): int
    {
        return (int) ($this->validated('limit') ?? 10);
    }

    public function source(): string
    {
        return (string) ($this->validated('source') ?? ReviewSession::SOURCE_DUE);
    }
}
