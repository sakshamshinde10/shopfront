<?php

namespace App\Http\Requests;

use App\Models\Source;
use Illuminate\Foundation\Http\FormRequest;

class AddSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:'.Source::LINK.','.Source::NOTE],
            'url' => ['required_if:kind,'.Source::LINK, 'nullable', 'url:http,https', 'max:2048'],
            'text' => ['required_if:kind,'.Source::NOTE, 'nullable', 'string', 'max:20000'],
            'label' => ['nullable', 'string', 'max:200'],
        ];
    }
}
