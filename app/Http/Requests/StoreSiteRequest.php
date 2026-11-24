<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'links' => ['sometimes', 'array', 'max:10'],
            'links.*' => ['required', 'url:http,https', 'max:2048'],
            'note' => ['nullable', 'string', 'max:20000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'business_name' => is_string($this->business_name) ? trim($this->business_name) : $this->business_name,
            'city' => is_string($this->city) ? trim($this->city) : $this->city,
        ]);
    }
}
