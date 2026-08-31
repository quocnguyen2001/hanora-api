<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Topic;
use App\Services\Topic\TopicCatalog;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class TopicStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 2-40 ký tự: đủ cho "phim ảnh" lẫn "đồ dùng nhà bếp", chặn được cả
            // chuỗi rỗng lẫn một đoạn văn nhét vào prompt.
            'name' => ['required', 'string', 'min:2', 'max:40'],
            'emoji' => ['nullable', 'string', 'max:8'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $userId = $this->user()->id;

            /*
             * Trần theo TÀI KHOẢN, không chỉ theo giờ.
             *
             * Limiter `topic-create` chặn 5/giờ, nhưng 5×24 mỗi ngày vẫn là 120
             * chủ đề — mỗi cái 2-3 lời gọi Gemini. Hai lớp chặn hai kiểu lạm
             * dụng khác nhau.
             */
            $owned = Topic::query()->where('user_id', $userId)->count();

            if ($owned >= Topic::MAX_PER_USER) {
                $validator->errors()->add('name', sprintf(
                    'Bạn đã có %d chủ đề tự tạo. Xoá bớt một chủ đề trước khi tạo thêm.',
                    Topic::MAX_PER_USER
                ));

                return;
            }

            $slug = $this->slug();

            if ($slug === '') {
                $validator->errors()->add('name', 'Tên chủ đề cần có chữ hoặc số.');

                return;
            }

            /*
             * TỪ CHỐI slug đụng chủ đề gốc, không tự thêm hậu tố.
             *
             * `/topics/{slug}` tra theo `(user_id = tôi OR user_id IS NULL)`,
             * nên một chủ đề riêng trùng slug sẽ CHE chủ đề gốc — người đó âm
             * thầm học một bộ từ khác với mọi người, không lỗi, không dấu hiệu.
             */
            if (TopicCatalog::has($slug)) {
                $validator->errors()->add('name', 'Đã có chủ đề sẵn với tên này. Thử một tên khác nhé.');

                return;
            }

            if (Topic::query()->where('user_id', $userId)->where('slug', $slug)->exists()) {
                $validator->errors()->add('name', 'Bạn đã tạo chủ đề với tên này rồi.');
            }
        });
    }

    /** Slug suy từ tên; `Str::slug` bỏ dấu tiếng Việt sẵn. */
    public function slug(): string
    {
        return Str::slug((string) $this->validated('name'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nhập tên chủ đề bạn muốn học.',
            'name.min' => 'Tên chủ đề quá ngắn.',
            'name.max' => 'Tên chủ đề tối đa 40 ký tự.',
        ];
    }
}
