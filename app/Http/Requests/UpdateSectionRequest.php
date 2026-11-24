<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Edits to one section. Only the fields listed here are accepted, whatever else is sent. */
class UpdateSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return match ($this->route('key')) {
            'hero' => [
                'title' => ['sometimes', 'required', 'string', 'max:150'],
                'city' => ['sometimes', 'nullable', 'string', 'max:100'],
                'tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            ],
            'about' => [
                'paragraphs' => ['required', 'array', 'max:6'],
                'paragraphs.*' => ['required', 'string', 'max:1200'],
            ],
            'services' => [
                'items' => ['sometimes', 'array', 'max:30'],
                'items.*.name' => ['required', 'string', 'max:100'],
                'items.*.description' => ['nullable', 'string', 'max:300'],
                'prices' => ['sometimes', 'array', 'max:30'],
                'prices.*' => ['required', 'string', 'max:150'],
            ],
            'contact' => [
                'phone' => ['sometimes', 'nullable', 'regex:/^[0-9+()\-.\s]{7,40}$/'],
                'email' => ['sometimes', 'nullable', 'email', 'max:254'],
                'address' => ['sometimes', 'nullable', 'string', 'max:300'],
                'hours' => ['sometimes', 'nullable', 'string', 'max:200'],
                // Only web links: anything else (javascript:, data:) must never reach a public page.
                'social' => ['sometimes', 'array', 'max:10'],
                'social.*' => ['required', 'url:http,https', 'max:2048'],
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Enter a valid phone number.'];
    }
}
