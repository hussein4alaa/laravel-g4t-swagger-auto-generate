<?php

namespace G4T\Swagger\Tests\Fixtures\Repositories;

use G4T\Swagger\Tests\Fixtures\Models\Post;
use G4T\Swagger\Tests\Fixtures\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class TicketRepository implements TicketRepositoryInterface
{
    public function store($request)
    {
        $data = $request->validated();
        $post = Post::where('title', $data['title'])->first();

        if (! $post) {
            return ApiResponse::responseError('Post not found', code: Response::HTTP_NOT_FOUND);
        }

        if (! $post->category_id) {
            return ApiResponse::responseError('This post has no category yet.', code: Response::HTTP_FORBIDDEN);
        }

        return ApiResponse::responseSuccess('Ticket created');
    }

    public function find($id)
    {
        return ApiResponse::responseSuccess('Ticket', ['id' => $id, 'title' => 'Broken screen']);
    }
}
