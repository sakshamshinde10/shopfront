<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            // "mimes" checks the real content of the file, not just its extension.
            'files.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,txt', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'files.*.mimes' => 'Only PDF, JPG, PNG, WEBP and TXT files are accepted.',
            'files.*.max' => 'Each file must be 10 MB or smaller.',
        ];
    }
}
