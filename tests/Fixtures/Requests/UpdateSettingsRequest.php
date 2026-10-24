<?php

namespace G4T\Swagger\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'locale' => 'required|string|in:en,ar',
            'notifications' => 'boolean',
        ];
    }
}
