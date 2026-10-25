<?php

namespace G4T\Swagger\Tests\Fixtures\Repositories;

interface TicketRepositoryInterface
{
    public function store($request);

    public function find($id);
}
