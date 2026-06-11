<?php

use App\Enums\EntityType;
use App\Models\Entity;
use Illuminate\Database\UniqueConstraintViolationException;

it('stores an entity with casts', function () {
    $entity = Entity::create([
        'type' => EntityType::Card,
        'name' => 'Ball Lightning',
        'slug' => 'ball-lightning',
        'description' => 'Deal 7 damage. Channel 1 Lightning.',
        'source_url' => 'https://sts2.untapped.gg/en/cards/ball-lightning',
        'metadata' => ['cost' => '1', 'rarity' => 'Common'],
        'provider' => 'untapped',
    ]);

    expect($entity->refresh())
        ->type->toBe(EntityType::Card)
        ->metadata->toBe(['cost' => '1', 'rarity' => 'Common']);
});

it('allows the same slug across types but not within one', function () {
    Entity::factory()->create(['type' => EntityType::Card, 'slug' => 'anchor']);
    Entity::factory()->create(['type' => EntityType::Relic, 'slug' => 'anchor']);

    expect(Entity::count())->toBe(2);

    Entity::factory()->create(['type' => EntityType::Card, 'slug' => 'anchor']);
})->throws(UniqueConstraintViolationException::class);

it('has a label for every type', function () {
    expect(EntityType::Card->label())->toBe('Card')
        ->and(EntityType::Enchant->label())->toBe('Enchant');
});
