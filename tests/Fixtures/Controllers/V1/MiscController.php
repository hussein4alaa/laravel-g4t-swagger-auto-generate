<?php

namespace G4T\Swagger\Tests\Fixtures\Controllers\V1;

use G4T\Swagger\Tests\Fixtures\Models\Category;
use G4T\Swagger\Tests\Fixtures\Requests\UpdateSettingsRequest;
use G4T\Swagger\Tests\Fixtures\Resources\CategoryResource;

class MiscController
{
    public function ping()
    {
        return response()->json([
            'status' => 'ok',
            'uptime' => 42,
        ]);
    }

    public function stats()
    {
        $total = Category::query()->count();
        $label = 'categories';

        return response()->json([
            'total' => $total,
            'label' => $label,
        ]);
    }

    public function categories()
    {
        return CategoryResource::collection(Category::all());
    }

    public function find(?string $id = null)
    {
        abort(404, 'Record not found.');
    }

    public function conflict()
    {
        if (request()->boolean('invalid')) {
            return response()->json([
                'message' => 'The state is invalid.',
                'errors' => ['state' => ['The state is invalid.']],
            ], 422);
        }

        return response()->json(['message' => 'Resource already locked.'], 409);
    }

    public function settings(UpdateSettingsRequest $request)
    {
        return response()->json(['saved' => true]);
    }

    public function profile()
    {
        return response()->json(['id' => 1, 'name' => 'Jane']);
    }

    public function documented()
    {
        return response()->json(['documented' => true]);
    }

    public function secret()
    {
        return response()->json(['secret' => true]);
    }
}
