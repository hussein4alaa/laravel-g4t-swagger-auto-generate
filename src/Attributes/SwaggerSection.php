<?php

namespace G4T\Swagger\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class SwaggerSection
{
    protected $value;

    public function __construct($value)
    {
        $this->value = $value;
    }

    public function getValue()
    {
        return $this->value;
    }
}
