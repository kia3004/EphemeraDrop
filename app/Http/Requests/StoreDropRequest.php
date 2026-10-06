<?php

namespace App\Http\Requests;

use App\Support\NotifyUrlPolicy;
use Illuminate\Foundation\Http\FormRequest;

class StoreDropRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:text,file'],
            'ciphertext' => ['required_if:kind,text', 'nullable', 'string', 'max:' . ceil(config('ephemera.max_text_size') * 1.5 + 64)],
            'max_views' => ['nullable', 'integer', 'min:1', 'max:10'],
            'expires_in' => ['nullable', 'integer', 'in:' . implode(',', config('ephemera.expiry_options'))],
            'has_password' => ['sometimes', 'boolean'],
            'kdf_salt' => ['required_if:has_password,true', 'nullable', 'string', 'size:22', 'regex:/^[A-Za-z0-9_-]+$/'],
            'notify_url' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! app(NotifyUrlPolicy::class)->isSafe($value)) {
                        $fail('The notification URL must use HTTPS and resolve only to public IP addresses.');
                    }
                },
            ],
            'size_bytes' => [
                'required_if:kind,file',
                'nullable',
                'integer',
                'min:1',
                'max:' . config('ephemera.max_file_size'),
            ],
            'chunk_count' => [
                'required_if:kind,file',
                'integer',
                'min:2',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ((int) $value !== $this->expectedChunkCount((int) $this->input('size_bytes'))) {
                        $fail('The encrypted chunk count does not match the declared file size.');
                    }
                },
            ],
        ];
    }

    private function expectedChunkCount(int $size): int
    {
        return 1 + (int) ceil($size / (int) config('ephemera.chunk_size'));
    }
}
