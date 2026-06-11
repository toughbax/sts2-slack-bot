<?php

use App\Enums\EntityType;
use App\Models\Entity;
use Illuminate\Support\Facades\Storage;

it('derives the local image path from the remote url', function () {
    $entity = Entity::factory()->make([
        'type' => EntityType::Card,
        'slug' => 'ball-lightning',
        'images' => [
            'portrait' => 'https://art.test/defect/ball_lightning.png',
            'preview' => 'https://preview.test/cards/ball-lightning.webp',
        ],
    ]);

    expect($entity->imagePath('portrait'))->toBe('images/card/ball-lightning-portrait.png')
        ->and($entity->imagePath('preview'))->toBe('images/card/ball-lightning-preview.webp')
        ->and($entity->imagePath('missing'))->toBeNull();
});

it('returns a public url only when the file exists', function () {
    Storage::fake('public');
    $entity = Entity::factory()->make([
        'type' => EntityType::Relic,
        'slug' => 'akabeko',
        'images' => ['portrait' => 'https://art.test/akabeko.png'],
    ]);

    expect($entity->localImageUrl('portrait'))->toBeNull();

    Storage::disk('public')->put('images/relic/akabeko-portrait.png', 'bytes');

    expect($entity->localImageUrl('portrait'))->toContain('/storage/images/relic/akabeko-portrait.png');
});
