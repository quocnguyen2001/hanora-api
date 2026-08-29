<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Review\AnswerGrader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubmitAnswerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Chỉ validate kiểu ở đây. Quyền sở hữu KHÔNG kiểm bằng
             * `exists:user_words,id` — luật đó chỉ nói bản ghi tồn tại, không
             * nói nó thuộc về ai. Controller resolve qua quan hệ của user hiện
             * tại và trả 404 (red team H1).
             */
            'user_word_id' => ['required', 'integer', 'min:1'],

            // Cùng lý do không dùng `exists:` như trên: luật đó nói bản ghi tồn
            // tại, không nói nó thuộc về ai.
            'review_session_id' => ['required', 'integer', 'min:1'],
            'mode' => ['required', Rule::in([AnswerGrader::MODE_MCQ, AnswerGrader::MODE_TYPING])],
            'answer_word_id' => ['required_if:mode,'.AnswerGrader::MODE_MCQ, 'integer', 'min:1'],
            'answer' => ['required_if:mode,'.AnswerGrader::MODE_TYPING, 'string', 'max:64'],

            /*
             * Thời gian trả lời thẻ này, do client đo. CHỈ để hiển thị — không
             * đụng điểm, xếp loại hay lịch SRS, nên client tự khai được mà
             * không ảnh hưởng gì ngoài chính họ.
             *
             * Trần 1 giờ: quá mốc đó thì người dùng đã bỏ tab chứ không còn
             * đang nghĩ, và một số nguyên không chặn là thứ không nên ghi thẳng
             * vào cột.
             */
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],

            /*
             * KHÔNG có `is_retry`.
             *
             * Cờ đó chi phối cả hình phạt SRS lẫn mẫu số của điểm. Nhận nó từ
             * client nghĩa là client tự chấm điểm mình: gửi `true` cho mọi câu
             * sai và `false` cho mọi câu đúng là ra 100 điểm. Server suy nó từ
             * `review_logs` của chính phiên — `ReviewSessionManager::isRetry()`.
             */
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_word_id.required' => 'Thiếu từ đang ôn.',
            'review_session_id.required' => 'Thiếu phiên ôn tập.',
            'mode.in' => 'Chế độ ôn tập không hợp lệ.',
            'answer_word_id.required_if' => 'Chưa chọn đáp án.',
            'answer.required_if' => 'Chưa nhập câu trả lời.',
            'answer.max' => 'Câu trả lời không được dài quá 64 ký tự.',
            'duration_ms.max' => 'Thời gian trả lời không hợp lệ.',
        ];
    }
}
