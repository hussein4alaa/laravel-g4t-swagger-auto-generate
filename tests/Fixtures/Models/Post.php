<?php

namespace G4T\Swagger\Tests\Fixtures\Models;

use G4T\Swagger\Tests\Fixtures\Enums\PostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => PostStatus::class,
        'is_featured' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
