<?php

use App\Enums\EntityType;
use App\Models\Entity;
use Tests\Concerns\SignsSlackRequests;

uses(SignsSlackRequests::class);

function optionsPayload(string $value): array
{
    return ['payload' => json_encode([
        'type' => 'block_suggestion',
        'action_id' => 'sts_entity_select',
        'value' => $value,
    ])];
}

it('returns fuzzy-matched options', function () {
    Entity::factory()->create(['type' => EntityType::Card, 'name' => 'Ball Lightning', 'slug' => 'ball-lightning']);
    Entity::factory()->create(['type' => EntityType::Relic, 'name' => 'Anchor', 'slug' => 'anchor']);

    $this->postSlack('/slack/options', optionsPayload('ball'))
        ->assertSuccessful()
        ->assertJsonCount(1, 'options')
        ->assertJsonPath('options.0.text.text', 'Ball Lightning · Card');
});

it('caps options at 25', function () {
    foreach (range(1, 30) as $i) {
        Entity::factory()->create(['type' => EntityType::Card, 'name' => "Fireball {$i}", 'slug' => "fireball-{$i}"]);
    }

    $this->postSlack('/slack/options', optionsPayload('fireball'))
        ->assertSuccessful()
        ->assertJsonCount(25, 'options');
});

it('returns no options for an empty query', function () {
    Entity::factory()->create(['type' => EntityType::Card, 'name' => 'Strike', 'slug' => 'strike']);

    $this->postSlack('/slack/options', optionsPayload(''))
        ->assertSuccessful()
        ->assertJsonCount(0, 'options');
});
