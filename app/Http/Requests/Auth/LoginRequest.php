<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            // Không đặt rule độ dài tối thiểu ở đây: đó là mật khẩu đã tồn tại,
            // và một thông báo "mật khẩu quá ngắn" ở màn đăng nhập chỉ tổ rò rỉ
            // luật đặt mật khẩu mà không giúp gì người dùng.
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không đúng định dạng.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
            'device_name.max' => 'Tên thiết bị không được dài quá 64 ký tự.',
        ];
    }
}
