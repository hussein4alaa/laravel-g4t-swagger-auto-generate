<?php

namespace G4T\Swagger\Tests\Fixtures\Extensions;

use G4T\Swagger\Tests\Fixtures\Extensions\Data\UserData;

class DataController
{
    public function store(UserData $data)
    {
        return UserData::from($data);
    }

    public function show(int $id)
    {
        return response()->json(['data' => UserData::from(['id' => $id])]);
    }
}
