<?php

use App\Enums\EntityType;
use App\Models\Entity;
use Tests\Concerns\SignsSlackRequests;

uses(SignsSlackRequests::class);

it('returns the select prompt when no text is given', function () {
    $this->postSlack('/slack/command', ['text' => ''])
        ->assertSuccessful()
        ->assertJsonPath('blocks.0.accessory.type', 'external_select');
});

it('returns the top match for a query', function () {
    Entity::factory()->create([
        'type' => EntityType::Card,
        'name' => 'Ball Lightning',
        'slug' => 'ball-lightning',
        'description' => 'Deal 7 damage.',
    ]);

    $this->postSlack('/slack/command', ['text' => 'ball lightning'])
        ->assertSuccessful()
        ->assertJsonPath('blocks.0.text.text', 'Ball Lightning');
});

it('offers did-you-mean buttons when ambiguous', function () {
    Entity::factory()->create(['type' => EntityType::Card, 'name' => 'Anchor', 'slug' => 'anchor']);
    Entity::factory()->create(['type' => EntityType::Relic, 'name' => 'Anchor', 'slug' => 'anchor']);

    $response = $this->postSlack('/slack/command', ['text' => 'anchor'])->assertSuccessful();

    $actions = collect($response->json('blocks'))->firstWhere('type', 'actions');
    expect($actions['elements'])->toHaveCount(1);
});

it('reports when nothing matches', function () {
    $this->postSlack('/slack/command', ['text' => 'zzqxv'])
        ->assertSuccessful()
        ->assertJsonPath('text', 'No Slay the Spire 2 entities match "zzqxv".');
});

it('rejects unsigned requests', function () {
    $this->post('/slack/command', ['text' => 'hi'])->assertUnauthorized();
});
