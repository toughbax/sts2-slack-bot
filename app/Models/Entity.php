<?php

namespace App\Models;

use App\Enums\EntityType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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

    public function imagePath(string $variant): ?string
    {
        if ($variant === 'comparison') {
            return $this->type === EntityType::Card ? "images/card/{$this->slug}-comparison.png" : null;
        }

        $url = $this->images[$variant] ?? null;

        if ($url === null) {
            return null;
        }

        $extension = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';

        return "images/{$this->type->value}/{$this->slug}-{$variant}.{$extension}";
    }

    public function localImageUrl(string $variant): ?string
    {
        $path = $this->imagePath($variant);

        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
