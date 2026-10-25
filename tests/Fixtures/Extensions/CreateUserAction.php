<?php

namespace G4T\Swagger\Tests\Fixtures\Extensions;

/**
 * Stand-in for a Laravel Actions class: the route points at the action, which owns its rules.
 */
class CreateUserAction
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'role' => ['required', 'in:admin,editor'],
        ];
    }

    public function __invoke()
    {
        return response()->json(['created' => true], 201);
    }
}
