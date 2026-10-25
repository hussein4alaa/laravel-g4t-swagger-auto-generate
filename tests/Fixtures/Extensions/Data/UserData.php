<?php

namespace G4T\Swagger\Tests\Fixtures\Extensions\Data;

use DateTimeImmutable;

class UserData extends Data
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public int $id,
        public string $email,
        public AddressData $address,
        public array $roles,
        public ?DateTimeImmutable $joinedAt = null,
        public bool $active = true,
    ) {}
}
