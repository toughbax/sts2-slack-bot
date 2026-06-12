<?php

use App\Enums\EntityType;
use App\Models\Entity;
use App\Slack\BlockKitFormatter;
use Illuminate\Support\Facades\Storage;

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

    $actions = collect($payload['blocks'])->firstWhere('block_id', 'sts_did_you_mean');
    expect($payload['response_type'])->toBe('ephemeral')
        ->and($actions['elements'][0]['value'])->toBe('8')
        ->and($actions['elements'][0]['action_id'])->toBe('sts_entity_button_8')
        ->and($actions['elements'][0]['text']['text'])->toBe('Ball of Fire · Potion · Defect');
});

it('renders a search response without buttons when unambiguous', function () {
    $payload = $this->formatter->searchResponse(fakeEntity(), collect());

    expect(collect($payload['blocks'])->firstWhere('block_id', 'sts_did_you_mean'))->toBeNull();
});

it('renders options for the external select', function () {
    $card = fakeEntity();
    $relic = fakeEntity(['id' => 9, 'name' => 'Anchor', 'type' => EntityType::Relic]);

    $payload = $this->formatter->options(collect([$card, $relic]));

    expect($payload['options'])->toBe([
        ['text' => ['type' => 'plain_text', 'text' => 'Ball Lightning · Card · Defect'], 'value' => '7'],
        ['text' => ['type' => 'plain_text', 'text' => 'Anchor · Relic · Defect'], 'value' => '9'],
    ]);
});

it('renders a no-results message', function () {
    $payload = $this->formatter->noResults('zzqxv');

    expect($payload['response_type'])->toBe('ephemeral')
        ->and($payload['text'])->toContain('zzqxv');
});

it('truncates an overlong name to the 150-char header limit', function () {
    $blocks = $this->formatter->entityCard(fakeEntity(['name' => str_repeat('A', 200)]));

    expect(mb_strlen($blocks[0]['text']['text']))->toBeLessThanOrEqual(150);
});

it('truncates an overlong description to the 3000-char section limit', function () {
    $blocks = $this->formatter->entityCard(fakeEntity(['description' => str_repeat('x', 4000)]));

    expect(mb_strlen($blocks[2]['text']['text']))->toBeLessThanOrEqual(3000);
});

it('escapes mrkdwn control characters in scraped text', function () {
    $blocks = $this->formatter->entityCard(fakeEntity([
        'description' => 'Deal damage equal to your <current> HP & more.',
    ]));

    expect($blocks[2]['text']['text'])
        ->toBe('Deal damage equal to your &lt;current&gt; HP &amp; more.');
});

it('renders a placeholder for an empty description', function () {
    $blocks = $this->formatter->entityCard(fakeEntity(['description' => '']));

    expect($blocks[2]['text']['text'])->toBe('_(no description)_');
});

it('appends a full image block for cards when the image is downloaded', function () {
    Storage::fake('public');
    Storage::disk('public')->put('images/card/ball-lightning-preview.png', 'bytes');

    $blocks = $this->formatter->entityCard(fakeEntity([
        'slug' => 'ball-lightning',
        'images' => ['preview' => 'https://preview.test/ball-lightning.png'],
    ]));

    $image = collect($blocks)->firstWhere('type', 'image');
    expect($image['image_url'])->toContain('/storage/images/card/ball-lightning-preview.png')
        ->and($image['alt_text'])->toBe('Ball Lightning')
        ->and(end($blocks)['type'])->toBe('context'); // source link stays last
});

it('attaches a thumbnail accessory for non-card entities', function () {
    Storage::fake('public');
    Storage::disk('public')->put('images/relic/akabeko-portrait.png', 'bytes');

    $blocks = $this->formatter->entityCard(fakeEntity([
        'type' => EntityType::Relic,
        'name' => 'Akabeko',
        'slug' => 'akabeko',
        'images' => ['portrait' => 'https://art.test/akabeko.png'],
    ]));

    $section = collect($blocks)->firstWhere('type', 'section');
    expect($section['accessory']['type'])->toBe('image')
        ->and($section['accessory']['image_url'])->toContain('akabeko-portrait.png')
        ->and(collect($blocks)->firstWhere('type', 'image'))->toBeNull();
});

it('renders without images when none are downloaded', function () {
    Storage::fake('public');

    $blocks = $this->formatter->entityCard(fakeEntity([
        'slug' => 'ball-lightning',
        'images' => ['preview' => 'https://preview.test/ball-lightning.png'],
    ]));

    expect(collect($blocks)->firstWhere('type', 'image'))->toBeNull();
});

it('prefers the comparison image over the plain preview', function () {
    Storage::fake('public');
    Storage::disk('public')->put('images/card/ball-lightning-comparison.png', 'bytes');
    Storage::disk('public')->put('images/card/ball-lightning-preview.png', 'bytes');

    $blocks = $this->formatter->entityCard(fakeEntity([
        'slug' => 'ball-lightning',
        'images' => ['preview' => 'https://preview.test/ball-lightning.png'],
    ]));

    expect(collect($blocks)->firstWhere('type', 'image')['image_url'])
        ->toContain('ball-lightning-comparison.png');
});

it('shows the upgraded description for cards that have one', function () {
    $blocks = $this->formatter->entityCard(fakeEntity([
        'metadata' => ['character' => 'Defect', 'upgraded_description' => 'Deal 10 damage & more.'],
    ]));

    $sections = collect($blocks)->where('type', 'section')->values();
    expect($sections)->toHaveCount(2)
        ->and($sections[1]['text']['text'])->toBe('*Upgraded (Ball Lightning+):* Deal 10 damage &amp; more.')
        ->and(end($blocks)['type'])->toBe('context');
});

it('adds a share button to search responses', function () {
    $payload = $this->formatter->searchResponse(fakeEntity(), collect());

    $share = collect($payload['blocks'])->firstWhere('block_id', 'sts_share');
    expect($share['elements'][0]['action_id'])->toBe('sts_share_button_7')
        ->and($share['elements'][0]['value'])->toBe('7');
});

it('keeps the share button before did-you-mean blocks', function () {
    $other = fakeEntity(['id' => 8, 'name' => 'Ball of Fire']);

    $payload = $this->formatter->searchResponse(fakeEntity(), collect([$other]));

    $blockIds = collect($payload['blocks'])->pluck('block_id')->filter()->values()->all();
    expect($blockIds)->toBe(['sts_share', 'sts_did_you_mean']);
});

it('builds an ephemeral card with a share button', function () {
    $payload = $this->formatter->ephemeralCard(fakeEntity());

    expect($payload['response_type'])->toBe('ephemeral')
        ->and($payload['replace_original'])->toBeTrue()
        ->and(collect($payload['blocks'])->firstWhere('block_id', 'sts_share'))->not->toBeNull();
});

it('builds a public shared card without buttons', function () {
    $payload = $this->formatter->sharedCard(fakeEntity(), 'U123');

    expect($payload['response_type'])->toBe('in_channel')
        ->and($payload['replace_original'])->toBeFalse()
        ->and(collect($payload['blocks'])->firstWhere('type', 'actions'))->toBeNull()
        ->and(collect($payload['blocks'])->last()['elements'][0]['text'])->toBe('Shared by <@U123>');
});
