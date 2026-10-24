<?php

namespace G4T\Swagger\Tests\Fixtures\Requests;

use G4T\Swagger\Tests\Fixtures\Enums\PostStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(PostStatus::class)],
        ];
    }
}
