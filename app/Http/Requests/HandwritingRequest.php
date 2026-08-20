<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bound cho nét vẽ gửi lên.
 *
 * Không giới hạn thì một request có thể đẩy hàng megabyte toạ độ và bắt server
 * chuyển tiếp nguyên xi ra ngoài — biến endpoint này thành bộ khuếch đại lưu
 * lượng miễn phí.
 */
final class HandwritingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Một chữ Hán nhiều nhất khoảng 36 nét; 64 là dư dả.
            'strokes' => ['required', 'array', 'min:1', 'max:64'],
            'strokes.*' => ['required', 'array', 'min:1', 'max:256'],
            'strokes.*.*' => ['required', 'array', 'size:2'],
            'strokes.*.*.*' => ['required', 'numeric', 'between:0,4096'],
            'width' => ['required', 'integer', 'between:16,2048'],
            'height' => ['required', 'integer', 'between:16,2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'strokes.required' => 'Chưa có nét nào để nhận dạng.',
            'strokes.max' => 'Quá nhiều nét.',
        ];
    }
}
