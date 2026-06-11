<?php

use App\Enums\EntityType;
use App\Import\FixtureProvider;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->path = storage_path('framework/testing/fixtures-'.uniqid());
    File::ensureDirectoryExists($this->path);
});

afterEach(function () {
    File::deleteDirectory($this->path);
});

it('yields entities from every json file in the directory', function () {
    File::put($this->path.'/cards.json', json_encode([[
        'type' => 'card',
        'name' => 'Ball Lightning',
        'slug' => 'ball-lightning-x',
        'description' => 'Deal 7 damage.',
        'source_url' => 'https://example.test/cards/ball-lightning',
        'metadata' => ['cost' => '1'],
    ]]));
    File::put($this->path.'/relics.json', json_encode([[
        'type' => 'relic',
        'name' => 'Akabeko',
        'description' => 'Gain 8 Vigor.',
    ]]));

    $entities = collect((new FixtureProvider($this->path))->entities());

    expect($entities)->toHaveCount(2)
        ->and($entities->firstWhere('name', 'Ball Lightning'))
        ->type->toBe(EntityType::Card)
        ->slug->toBe('ball-lightning-x')
        ->metadata->toBe(['cost' => '1'])
        ->and($entities->firstWhere('name', 'Akabeko'))
        ->slug->toBeNull()
        ->sourceUrl->toBeNull()
        ->metadata->toBe([]);
});

it('yields nothing from an empty directory', function () {
    expect(collect((new FixtureProvider($this->path))->entities()))->toBeEmpty();
});

it('throws on malformed json', function () {
    File::put($this->path.'/broken.json', '{nope');

    collect((new FixtureProvider($this->path))->entities());
})->throws(JsonException::class);
