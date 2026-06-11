<?php

use App\Enums\EntityType;
use App\Models\Entity;
use App\Slack\BlockKitFormatter;

beforeEach(function () {
    $this->formatter = new BlockKitFormatter();
});

function fakeEntity(array $attributes = []): Entity
{
    return Entity::factory()->make(array_merge([
        'id' => 7,
        'type' => EntityType::Card,
        'name' => 'Ball Lightning',
        'description' => 'Deal 7 damage. Channel 1 Lightning.',
        'source_url' => 'https://sts2.untapped.gg/en/cards/ball-lightning',
        'metadata' => ['cost' => '1', 'rarity' => 'Common', 'character' => 'Defect', 'card_type' => 'Attack'],
    ], $attributes));
}

it('renders an entity card', function () {
    $blocks = $this->formatter->entityCard(fakeEntity());

    expect($blocks[0])->toBe([
        'type' => 'header',
        'text' => ['type' => 'plain_text', 'text' => 'Ball Lightning', 'emoji' => true],
    ]);
    expect($blocks[1]['elements'][0]['text'])->toBe('Card · Defect · Common · 1 Cost · Attack');
    expect($blocks[2]['text']['text'])->toBe('Deal 7 damage. Channel 1 Lightning.');
    expect($blocks[3]['elements'][0]['text'])
        ->toBe('<https://sts2.untapped.gg/en/cards/ball-lightning|View on sts2.untapped.gg>');
});

it('omits the source block when there is no url', function () {
    $blocks = $this->formatter->entityCard(fakeEntity(['source_url' => null]));

    expect($blocks)->toHaveCount(3);
});

it('renders the select prompt with an external select', function () {
    $payload = $this->formatter->selectPrompt();

    expect($payload['response_type'])->toBe('ephemeral')
        ->and($payload['blocks'][0]['accessory']['type'])->toBe('external_select')
        ->and($payload['blocks'][0]['accessory']['action_id'])->toBe('sts_entity_select')
        ->and($payload['blocks'][0]['accessory']['min_query_length'])->toBe(2);
});

it('renders a search response with did-you-mean buttons', function () {
    $top = fakeEntity();
    $other = fakeEntity(['id' => 8, 'name' => 'Ball of Fire', 'type' => EntityType::Potion]);

    $payload = $this->formatter->searchResponse($top, collect([$other]));

    $actions = collect($payload['blocks'])->firstWhere('type', 'actions');
    expect($payload['response_type'])->toBe('ephemeral')
        ->and($actions['elements'][0]['value'])->toBe('8')
        ->and($actions['elements'][0]['action_id'])->toBe('sts_entity_button_8')
        ->and($actions['elements'][0]['text']['text'])->toBe('Ball of Fire · Potion');
});

it('renders a search response without buttons when unambiguous', function () {
    $payload = $this->formatter->searchResponse(fakeEntity(), collect());

    expect(collect($payload['blocks'])->firstWhere('type', 'actions'))->toBeNull();
});

it('renders options for the external select', function () {
    $card = fakeEntity();
    $relic = fakeEntity(['id' => 9, 'name' => 'Anchor', 'type' => EntityType::Relic]);

    $payload = $this->formatter->options(collect([$card, $relic]));

    expect($payload['options'])->toBe([
        ['text' => ['type' => 'plain_text', 'text' => 'Ball Lightning · Card'], 'value' => '7'],
        ['text' => ['type' => 'plain_text', 'text' => 'Anchor · Relic'], 'value' => '9'],
    ]);
});

it('renders a no-results message', function () {
    $payload = $this->formatter->noResults('zzqxv');

    expect($payload['response_type'])->toBe('ephemeral')
        ->and($payload['text'])->toContain('zzqxv');
});
