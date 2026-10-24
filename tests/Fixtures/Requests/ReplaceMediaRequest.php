<?php

namespace G4T\Swagger\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceMediaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:jpg,png',
            'alt' => 'nullable|string',
        ];
    }
}
