<?php

use App\Enums\EntityType;
use App\Import\ImportedEntity;

it('is a readonly value object', function () {
    $imported = new ImportedEntity(
        type: EntityType::Relic,
        name: 'Akabeko',
        description: 'At the start of each combat, gain 8 Vigor.',
        sourceUrl: 'https://sts2.untapped.gg/en/relics/akabeko',
        metadata: ['rarity' => 'Uncommon'],
    );

    expect($imported->type)->toBe(EntityType::Relic)
        ->and($imported->metadata)->toBe(['rarity' => 'Uncommon']);
});
