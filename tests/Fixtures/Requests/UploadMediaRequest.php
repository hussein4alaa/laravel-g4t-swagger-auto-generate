<?php

namespace G4T\Swagger\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadMediaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cover' => 'required|image|max:2048',
            'document' => ['nullable', 'file', 'mimes:pdf,docx'],
            'gallery' => 'array',
            'gallery.*' => 'image',
            'caption' => 'nullable|string',
        ];
    }
}
