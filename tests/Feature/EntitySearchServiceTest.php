<?php

use App\Enums\EntityType;
use App\Models\Entity;
use App\Services\EntitySearchService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->search = new EntitySearchService();
});

function makeEntity(string $name, EntityType $type = EntityType::Card): Entity
{
    return Entity::factory()->create([
        'type' => $type,
        'name' => $name,
        'slug' => Str::slug($name),
    ]);
}

it('ranks exact match above prefix above substring', function () {
    makeEntity('Strike Out');       // prefix
    makeEntity('Counter Strike');   // substring
    makeEntity('Strike');           // exact

    $results = $this->search->search('strike');

    expect($results->pluck('name')->all())
        ->toBe(['Strike', 'Strike Out', 'Counter Strike']);
});

it('matches despite typos', function () {
    makeEntity('Hardstone');

    // 'hardstoen' is a transposition, not a substring, so this exercises the trigram branch.
    expect($this->search->search('hardstoen')->pluck('name')->all())
        ->toContain('Hardstone');
});

it('returns nothing for gibberish', function () {
    makeEntity('Ball Lightning');

    expect($this->search->search('zzqxv'))->toBeEmpty();
});

it('returns both entities when a name exists in two types', function () {
    makeEntity('Anchor', EntityType::Card);
    makeEntity('Anchor', EntityType::Relic);

    expect($this->search->search('anchor'))->toHaveCount(2);
});

it('respects the limit', function () {
    foreach (range(1, 30) as $i) {
        makeEntity("Fireball {$i}");
    }

    expect($this->search->search('fireball', 25))->toHaveCount(25);
});

it('returns nothing for an empty query', function () {
    makeEntity('Strike');

    expect($this->search->search('  '))->toBeEmpty();
});
