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
        ->expectsOutputToContain('2 downloaded, 0 skipped, 0 composed, 0 pruned, 0 failed')
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
        ->expectsOutputToContain('0 downloaded, 1 skipped, 0 composed, 0 pruned, 0 failed')
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
        ->expectsOutputToContain('0 downloaded, 0 skipped, 0 composed, 0 pruned, 1 failed')
        ->assertFailed();

    Storage::disk('public')->assertMissing('images/card/ghost-portrait.png');
});

it('treats connection errors as failures and keeps going', function () {
    Entity::factory()->create([
        'type' => EntityType::Card,
        'slug' => 'unreachable',
        'images' => ['portrait' => 'https://down.test/unreachable.png'],
    ]);
    Entity::factory()->create([
        'type' => EntityType::Relic,
        'slug' => 'akabeko',
        'images' => ['portrait' => 'https://art.test/akabeko.png'],
    ]);
    Http::fake([
        'https://down.test/*' => fn () => throw new Illuminate\Http\Client\ConnectionException('Could not resolve host'),
        'https://art.test/*' => Http::response('png-bytes'),
    ]);

    $this->artisan('sts:images')
        ->expectsOutputToContain('1 downloaded, 0 skipped, 0 composed, 0 pruned, 1 failed')
        ->assertFailed();

    Storage::disk('public')->assertExists('images/relic/akabeko-portrait.png');
});

it('does nothing when entities have no images', function () {
    Entity::factory()->create(['images' => []]);
    Http::fake();

    $this->artisan('sts:images')
        ->expectsOutputToContain('0 downloaded, 0 skipped, 0 composed, 0 pruned, 0 failed')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('composes side-by-side comparison images for upgraded cards', function () {
    $png = function (int $w, int $h): string {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    };

    Entity::factory()->create([
        'type' => EntityType::Card,
        'slug' => 'ball-lightning',
        'metadata' => ['upgraded_description' => 'Deal 10 damage.'],
        'images' => [
            'preview' => 'https://preview.test/ball-lightning.png',
            'preview_upgraded' => 'https://preview.test/ball-lightning-upgraded.png',
        ],
    ]);
    Http::fake([
        'https://preview.test/ball-lightning.png' => Http::response($png(100, 150)),
        'https://preview.test/ball-lightning-upgraded.png' => Http::response($png(100, 150)),
    ]);

    $this->artisan('sts:images')
        ->expectsOutputToContain('2 downloaded, 0 skipped, 1 composed, 0 pruned, 0 failed')
        ->assertSuccessful();

    Storage::disk('public')->assertExists('images/card/ball-lightning-comparison.png');
    [$w, $h] = getimagesizefromstring(Storage::disk('public')->get('images/card/ball-lightning-comparison.png'));
    expect($w)->toBe(216)->and($h)->toBe(150); // 100 + 16 gutter + 100
});

it('prunes intermediates after composing when asked', function () {
    $png = function (int $w, int $h): string {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    };

    // upgraded card: keeps only its comparison
    Entity::factory()->create([
        'type' => EntityType::Card,
        'slug' => 'ball-lightning',
        'metadata' => ['upgraded_description' => 'Deal 10 damage.'],
        'images' => [
            'portrait' => 'https://art.test/ball_lightning.png',
            'preview' => 'https://preview.test/ball-lightning.png',
            'preview_upgraded' => 'https://preview.test/ball-lightning-upgraded.png',
        ],
    ]);
    // upgrade-less card: keeps its preview, loses its portrait
    Entity::factory()->create([
        'type' => EntityType::Card,
        'slug' => 'wound',
        'images' => [
            'portrait' => 'https://art.test/wound.png',
            'preview' => 'https://preview.test/wound.png',
        ],
    ]);
    // relic: untouched
    Entity::factory()->create([
        'type' => EntityType::Relic,
        'slug' => 'akabeko',
        'images' => ['portrait' => 'https://art.test/akabeko.png'],
    ]);
    Http::fake([
        'https://art.test/*' => Http::response($png(50, 50)),
        'https://preview.test/*' => Http::response($png(100, 150)),
    ]);

    $this->artisan('sts:images', ['--prune' => true])
        ->expectsOutputToContain('6 downloaded, 0 skipped, 1 composed, 4 pruned, 0 failed')
        ->assertSuccessful();

    $disk = Storage::disk('public');
    $disk->assertExists('images/card/ball-lightning-comparison.png');
    $disk->assertMissing('images/card/ball-lightning-portrait.png');
    $disk->assertMissing('images/card/ball-lightning-preview.png');
    $disk->assertMissing('images/card/ball-lightning-preview_upgraded.png');
    $disk->assertExists('images/card/wound-preview.png');
    $disk->assertMissing('images/card/wound-portrait.png');
    $disk->assertExists('images/relic/akabeko-portrait.png');
});
