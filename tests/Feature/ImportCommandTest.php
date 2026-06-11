<?php

use App\Enums\EntityType;
use App\Import\ImportedEntity;
use App\Import\ImportProvider;
use App\Models\Entity;
use Illuminate\Support\Facades\File;

final class FakeProvider implements ImportProvider
{
    /** @var list<ImportedEntity> */
    public static array $entities = [];

    public function name(): string
    {
        return 'fake';
    }

    public function entities(): iterable
    {
        return self::$entities;
    }
}

beforeEach(function () {
    config()->set('sts.providers.fake', FakeProvider::class);
    FakeProvider::$entities = [
        new ImportedEntity(EntityType::Card, 'Ball Lightning', 'Deal 7 damage.', 'https://example.test/bl', ['cost' => '1']),
        new ImportedEntity(EntityType::Relic, 'Akabeko', 'Gain 8 Vigor.'),
    ];
});

it('creates entities from the provider', function () {
    $this->artisan('sts:import', ['--provider' => 'fake'])
        ->expectsOutputToContain('2 created, 0 updated')
        ->assertSuccessful();

    expect(Entity::count())->toBe(2)
        ->and(Entity::firstWhere('slug', 'ball-lightning'))
        ->name->toBe('Ball Lightning')
        ->provider->toBe('fake')
        ->metadata->toBe(['cost' => '1']);
});

it('updates instead of duplicating on re-import', function () {
    $this->artisan('sts:import', ['--provider' => 'fake'])->assertSuccessful();

    FakeProvider::$entities[0] = new ImportedEntity(EntityType::Card, 'Ball Lightning', 'Deal 8 damage.');

    $this->artisan('sts:import', ['--provider' => 'fake'])
        ->expectsOutputToContain('0 created, 2 updated')
        ->assertSuccessful();

    expect(Entity::count())->toBe(2)
        ->and(Entity::firstWhere('slug', 'ball-lightning')->description)->toBe('Deal 8 damage.');
});

it('uses the configured default provider', function () {
    config()->set('sts.default_provider', 'fake');

    $this->artisan('sts:import')->assertSuccessful();

    expect(Entity::count())->toBe(2);
});

it('fails on an unknown provider', function () {
    $this->artisan('sts:import', ['--provider' => 'nope'])
        ->expectsOutputToContain('Unknown provider')
        ->assertFailed();
});

it('writes fixtures when asked', function () {
    $path = storage_path('framework/testing/fixtures-'.uniqid());
    config()->set('sts.fixture_path', $path);

    $this->artisan('sts:import', ['--provider' => 'fake', '--save-fixtures' => true])
        ->assertSuccessful();

    $cards = json_decode(File::get($path.'/cards.json'), true);
    expect($cards)->toHaveCount(1)
        ->and($cards[0]['name'])->toBe('Ball Lightning')
        ->and($cards[0]['metadata'])->toBe(['cost' => '1']);

    File::deleteDirectory($path);
});
