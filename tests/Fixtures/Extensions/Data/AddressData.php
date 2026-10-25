<?php

namespace G4T\Swagger\Tests\Fixtures\Extensions\Data;

class AddressData extends Data
{
    public function __construct(
        public string $city,
        public ?string $zip = null,
    ) {}
}
