<?php

namespace G4T\Swagger\Tests\Fixtures\Models;

use G4T\Swagger\Tests\Fixtures\Enums\PostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    protected $guarded = [];

    protected $casts = [
        // Enum cast where PHP has enums (8.1+); a plain string on PHP 8.0.
        'status' => PHP_VERSION_ID >= 80100 ? PostStatus::class : 'string',
        'is_featured' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
