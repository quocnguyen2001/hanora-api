<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Dictionary\WordSearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            /*
             * `nullable`, KHÔNG phải `required`.
             *
             * Thiếu `mode` thì service rơi về `QueryClassifier` như trước. Hai lý
             * do giữ đường đó: service worker đã cache các URL không có `mode`,
             * và ai gọi API trực tiếp vẫn phải chạy đúng.
             *
             * Nhưng giá trị LẠ thì 422, không im lặng rơi về auto — `mode=vn`
             * gõ nhầm mà vẫn trả 200 thì client không bao giờ biết mình sai.
             */
            'mode' => ['nullable', Rule::in([WordSearchService::MODE_VI, WordSearchService::MODE_CN])],
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

    /** `null` = để `QueryClassifier` tự đoán, như trước khi có toggle. */
    public function mode(): ?string
    {
        $mode = $this->validated('mode');

        return $mode === null ? null : (string) $mode;
    }
}
