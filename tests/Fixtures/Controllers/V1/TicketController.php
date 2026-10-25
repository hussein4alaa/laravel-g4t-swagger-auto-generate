<?php

namespace G4T\Swagger\Tests\Fixtures\Controllers\V1;

use G4T\Swagger\Tests\Fixtures\Repositories\TicketRepository;
use G4T\Swagger\Tests\Fixtures\Repositories\TicketRepositoryInterface;
use G4T\Swagger\Tests\Fixtures\Requests\StorePostRequest;

class TicketController
{
    private $legacy;

    public function __construct(private TicketRepositoryInterface $tickets, TicketRepository $repository)
    {
        $this->legacy = $repository;
    }

    public function store(StorePostRequest $request)
    {
        return $this->tickets->store($request);
    }

    public function show(int $id)
    {
        return $this->tickets->find($id);
    }

    public function legacy(int $id)
    {
        return $this->legacy->find($id);
    }

    public function close()
    {
        return $this->queued();
    }

    private function queued()
    {
        return response()->json(['queued' => true], 202);
    }
}
