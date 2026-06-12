<?php

use App\Enums\EntityType;
use App\Models\Entity;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsSlackRequests;

uses(SignsSlackRequests::class);

function interactionPayload(array $action, ?string $userId = null): array
{
    $payload = [
        'type' => 'block_actions',
        'response_url' => 'https://hooks.slack.test/response',
        'actions' => [$action],
    ];

    if ($userId !== null) {
        $payload['user'] = ['id' => $userId];
    }

    return ['payload' => json_encode($payload)];
}

it('replaces the message with the card when a select option is chosen', function () {
    Http::fake();
    $entity = Entity::factory()->create([
        'type' => EntityType::Card,
        'name' => 'Ball Lightning',
        'slug' => 'ball-lightning',
    ]);

    $this->postSlack('/slack/interactions', interactionPayload([
        'action_id' => 'sts_entity_select',
        'selected_option' => ['value' => (string) $entity->id],
    ]))->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/response'
        && $request['replace_original'] === true
        && $request['blocks'][0]['text']['text'] === 'Ball Lightning');
});

it('handles did-you-mean button presses', function () {
    Http::fake();
    $entity = Entity::factory()->create([
        'type' => EntityType::Relic,
        'name' => 'Anchor',
        'slug' => 'anchor',
    ]);

    $this->postSlack('/slack/interactions', interactionPayload([
        'action_id' => "sts_entity_button_{$entity->id}",
        'value' => (string) $entity->id,
    ]))->assertSuccessful();

    Http::assertSent(fn ($request) => $request['blocks'][0]['text']['text'] === 'Anchor');
});

it('reports a vanished entity gracefully', function () {
    Http::fake();

    $this->postSlack('/slack/interactions', interactionPayload([
        'action_id' => 'sts_entity_select',
        'selected_option' => ['value' => '999'],
    ]))->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['text'], 'no longer'));
});

it('rejects unsigned requests', function () {
    $this->post('/slack/interactions', interactionPayload([
        'action_id' => 'sts_entity_select',
        'selected_option' => ['value' => '1'],
    ]))->assertUnauthorized();
});

it('shares the card to the channel and deletes the preview', function () {
    Http::fake();
    $entity = Entity::factory()->create([
        'type' => EntityType::Card,
        'name' => 'Ball Lightning',
        'slug' => 'ball-lightning',
    ]);

    $this->postSlack('/slack/interactions', interactionPayload([
        'action_id' => "sts_share_button_{$entity->id}",
        'value' => (string) $entity->id,
    ], 'U123'))->assertSuccessful();

    Http::assertSent(fn ($request) => ($request['response_type'] ?? null) === 'in_channel'
        && ($request['replace_original'] ?? null) === false
        && $request['blocks'][0]['text']['text'] === 'Ball Lightning');
    Http::assertSent(fn ($request) => ($request['delete_original'] ?? null) === true);
});
