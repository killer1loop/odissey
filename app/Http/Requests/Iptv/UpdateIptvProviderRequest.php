<?php

namespace App\Http\Requests\Iptv;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateIptvProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'provider_type' => $this->input('provider_type', $this->route('provider')?->config['api'] ?? 'xtream'),
            'allow_insecure_http' => $this->boolean('allow_insecure_http'),
            'enabled' => $this->boolean('enabled'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('iptv_providers', 'name')->ignore($this->route('provider')),
            ],
            'base_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'provider_type' => ['required', 'in:xtream,m3u'],
            'playlist_url' => ['nullable', 'string', 'max:4096', 'url:http,https'],
            'xmltv_url' => ['nullable', 'string', 'max:4096', 'url:http,https'],
            'max_connections' => ['nullable', 'integer', 'min:1', 'max:100'],
            'username' => ['nullable', 'string', 'max:1024'],
            'password' => ['nullable', 'string', 'max:1024'],
            'allow_insecure_http' => ['boolean'],
            'enabled' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $provider = $this->route('provider');
                $baseUrl = (string) (
                    $this->input('base_url')
                    ?: ($provider?->base_url ?? '')
                );

                if (
                    str_starts_with(strtolower($baseUrl), 'http://')
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
