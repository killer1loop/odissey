<?php

namespace App\Http\Requests\Iptv;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreIptvProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'allow_insecure_http' => $this->boolean('allow_insecure_http'),
            'enabled' => $this->boolean('enabled'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:iptv_providers,name'],
            'base_url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'username' => ['required', 'string', 'max:1024'],
            'password' => ['required', 'string', 'max:1024'],
            'allow_insecure_http' => ['boolean'],
            'enabled' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    str_starts_with(strtolower((string) $this->input('base_url')), 'http://')
                    && ! $this->boolean('allow_insecure_http')
                ) {
                    $validator->errors()->add(
                        'allow_insecure_http',
                        'Explicit consent is required for a provider that does not use HTTPS.',
                    );
                }
            },
        ];
    }
}
