<?php

namespace G4T\Swagger\Tests\Fixtures\Requests;

use G4T\Swagger\Tests\Fixtures\Enums\PostStatusRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPostsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:100',
            'page' => 'required|integer|min:1',
            'status' => ['nullable', PostStatusRule::make()],
        ];
    }
}
