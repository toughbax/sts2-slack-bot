<?php

namespace App\Models;

use App\Enums\EntityType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Entity extends Model
{
    /** @use HasFactory<\Database\Factories\EntityFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'name',
        'slug',
        'description',
        'source_url',
        'metadata',
        'images',
        'provider',
    ];

    protected function casts(): array
    {
        return [
            'type' => EntityType::class,
            'metadata' => 'array',
            'images' => 'array',
        ];
    }
}
