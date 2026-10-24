<?php

namespace G4T\Swagger\Tests\Fixtures\Requests;

use G4T\Swagger\Tests\Fixtures\Enums\PostStatusRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // string syntax
            'title' => 'required|string|max:255',
            // array syntax
            'body' => ['nullable', 'string'],
            'status' => ['required', PostStatusRule::make()],
            'priority' => 'required|in:low,medium,high',
            'category_id' => 'required|integer|exists:categories,id',
            'reference' => 'nullable|uuid',
            'published_at' => 'nullable|date',
            'is_featured' => 'boolean',
            'price' => 'numeric',
            'reason' => 'required_if:status,draft|string',
            'password' => 'required|string|min:8|confirmed',
            // nested object
            'author.name' => 'required|string',
            'author.email' => 'required|email',
            // array of scalars
            'tags' => 'array',
            'tags.*' => 'string',
            // array of objects
            'items' => 'required|array',
            'items.*.sku' => 'required|string',
            'items.*.qty' => 'required|integer|min:1',
        ];
    }
}
