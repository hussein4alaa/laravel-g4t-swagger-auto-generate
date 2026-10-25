<?php

namespace G4T\Swagger\Tests\Fixtures\Controllers\V1;

use G4T\Swagger\Tests\Fixtures\Models\Post;
use G4T\Swagger\Tests\Fixtures\Requests\StorePostRequest;
use G4T\Swagger\Tests\Fixtures\Resources\PostResource;
use G4T\Swagger\Tests\Fixtures\Support\Responder;
use G4T\Swagger\Tests\Fixtures\Support\RespondsWithJson;
use Illuminate\Routing\Controller;

/**
 * Every action answers through a response wrapper.
 */
class WrappedController extends Controller
{
    use RespondsWithJson;

    public function index()
    {
        return Responder::success(PostResource::collection(Post::query()->paginate()));
    }

    public function store(StorePostRequest $request)
    {
        $post = Post::create($request->validated());

        return Responder::success(new PostResource($post), 'Post created', 201);
    }

    public function show(int $id)
    {
        $post = Post::findOrFail($id);

        return Responder::success(new PostResource($post));
    }

    public function model(int $id)
    {
        return Responder::success(Post::findOrFail($id));
    }

    public function assigned(int $id)
    {
        $post = Post::query()->findOrFail($id);

        return Responder::success($post, 'Post');
    }

    public function helper(int $id)
    {
        return api_success(new PostResource(Post::findOrFail($id)), 'From a helper');
    }

    public function token()
    {
        return Responder::created(['token' => 'abc123']);
    }

    public function trait(int $id)
    {
        return $this->successResponse(new PostResource(Post::findOrFail($id)));
    }

    public function missing()
    {
        return Responder::error('Post not found', 404);
    }
}
