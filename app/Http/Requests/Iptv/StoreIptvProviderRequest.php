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
            'provider_type' => $this->input('provider_type', 'xtream'),
            'allow_insecure_http' => $this->boolean('allow_insecure_http'),
            'enabled' => $this->boolean('enabled'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:iptv_providers,name'],
            'provider_type' => ['required', 'in:xtream,m3u'],
            'base_url' => ['required_if:provider_type,xtream', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'playlist_url' => ['required_if:provider_type,m3u', 'nullable', 'string', 'max:4096', 'url:http,https'],
            'xmltv_url' => ['nullable', 'string', 'max:4096', 'url:http,https'],
            'max_connections' => ['nullable', 'integer', 'min:1', 'max:100'],
            'username' => ['required_if:provider_type,xtream', 'nullable', 'string', 'max:1024'],
            'password' => ['required_if:provider_type,xtream', 'nullable', 'string', 'max:1024'],
            'allow_insecure_http' => ['boolean'],
            'enabled' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    str_starts_with(strtolower((string) ($this->input('base_url') ?: $this->input('playlist_url'))), 'http://')
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
