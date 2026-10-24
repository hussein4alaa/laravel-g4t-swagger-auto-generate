<?php

namespace G4T\Swagger\Tests\Fixtures\Controllers\V1;

use G4T\Swagger\Tests\Fixtures\Requests\ReplaceMediaRequest;
use G4T\Swagger\Tests\Fixtures\Requests\UploadMediaRequest;

class MediaController
{
    public function upload(UploadMediaRequest $request)
    {
        return response()->json(['uploaded' => true, 'path' => 'media/cover.png'], 201);
    }

    public function replace(ReplaceMediaRequest $request, int $id)
    {
        return response()->json(['replaced' => true, 'id' => 1]);
    }
}
