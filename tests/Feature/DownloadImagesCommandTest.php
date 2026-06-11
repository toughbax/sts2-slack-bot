<?php

use App\Enums\EntityType;
use App\Models\Entity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

it('downloads every image variant to the public disk', function () {
    Entity::factory()->create([
        'type' => EntityType::Card,
        'slug' => 'ball-lightning',
        'images' => [
            'portrait' => 'https://art.test/ball_lightning.png',
            'preview' => 'https://preview.test/ball-lightning.webp',
        ],
    ]);
    Http::fake([
        'https://art.test/*' => Http::response('png-bytes'),
        'https://preview.test/*' => Http::response('webp-bytes'),
    ]);

    $this->artisan('sts:images')
        ->expectsOutputToContain('2 downloaded, 0 skipped, 0 failed')
        ->assertSuccessful();

    Storage::disk('public')->assertExists('images/card/ball-lightning-portrait.png');
    Storage::disk('public')->assertExists('images/card/ball-lightning-preview.webp');
    expect(Storage::disk('public')->get('images/card/ball-lightning-portrait.png'))->toBe('png-bytes');
});

it('skips images that already exist', function () {
    Entity::factory()->create([
        'type' => EntityType::Relic,
        'slug' => 'akabeko',
        'images' => ['portrait' => 'https://art.test/akabeko.png'],
    ]);
    Storage::disk('public')->put('images/relic/akabeko-portrait.png', 'old-bytes');
    Http::fake();

    $this->artisan('sts:images')
        ->expectsOutputToContain('0 downloaded, 1 skipped, 0 failed')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(Storage::disk('public')->get('images/relic/akabeko-portrait.png'))->toBe('old-bytes');
});

it('re-downloads with --force', function () {
    Entity::factory()->create([
        'type' => EntityType::Relic,
        'slug' => 'akabeko',
        'images' => ['portrait' => 'https://art.test/akabeko.png'],
    ]);
    Storage::disk('public')->put('images/relic/akabeko-portrait.png', 'old-bytes');
    Http::fake(['https://art.test/*' => Http::response('new-bytes')]);

    $this->artisan('sts:images', ['--force' => true])->assertSuccessful();

    expect(Storage::disk('public')->get('images/relic/akabeko-portrait.png'))->toBe('new-bytes');
});

it('reports failures and exits non-zero', function () {
    Entity::factory()->create([
        'type' => EntityType::Card,
        'slug' => 'ghost',
        'images' => ['portrait' => 'https://art.test/ghost.png'],
    ]);
    Http::fake(['https://art.test/*' => Http::response('', 404)]);

    $this->artisan('sts:images')
        ->expectsOutputToContain('0 downloaded, 0 skipped, 1 failed')
        ->assertFailed();

    Storage::disk('public')->assertMissing('images/card/ghost-portrait.png');
});

it('does nothing when entities have no images', function () {
    Entity::factory()->create(['images' => []]);
    Http::fake();

    $this->artisan('sts:images')
        ->expectsOutputToContain('0 downloaded, 0 skipped, 0 failed')
        ->assertSuccessful();

    Http::assertNothingSent();
});
