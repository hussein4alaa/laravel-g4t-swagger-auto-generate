<?php

namespace G4T\Swagger\Tests\Fixtures\Controllers\V1;

use G4T\Swagger\Attributes\SwaggerSection;
use G4T\Swagger\Tests\Fixtures\Models\Post;
use G4T\Swagger\Tests\Fixtures\Requests\ListPostsRequest;
use G4T\Swagger\Tests\Fixtures\Requests\StorePostRequest;
use G4T\Swagger\Tests\Fixtures\Requests\UpdatePostRequest;
use G4T\Swagger\Tests\Fixtures\Resources\PostResource;

#[SwaggerSection('Blog posts management')]
class PostController
{
    public function index(ListPostsRequest $request)
    {
        return PostResource::collection(Post::query()->paginate(15));
    }

    public function store(StorePostRequest $request)
    {
        if (Post::query()->where('title', $request->input('title'))->exists()) {
            return response()->json(['message' => 'A post with this title already exists.'], 409);
        }

        $post = Post::query()->create($request->validated());

        return new PostResource($post);
    }

    public function show(Post $post)
    {
        return new PostResource($post->load('category'));
    }

    public function update(UpdatePostRequest $request, Post $post)
    {
        $post->update($request->validated());

        return new PostResource($post);
    }

    public function destroy(Post $post)
    {
        $post->delete();

        return response()->noContent();
    }
}
