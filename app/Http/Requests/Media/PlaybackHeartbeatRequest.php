<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class PlaybackHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'sequence' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'position_ms' => ['required', 'integer', 'min:0', 'max:604800000'],
            'duration_ms' => ['nullable', 'integer', 'min:1', 'max:604800000'],
            'completed' => ['sometimes', 'boolean'],
        ];
    }
}
