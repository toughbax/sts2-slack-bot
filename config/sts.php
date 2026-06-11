<?php

use App\Import\FixtureProvider;
use App\Import\UntappedProvider;

return [
    'default_provider' => env('STS_IMPORT_PROVIDER', 'fixture'),

    'providers' => [
        'fixture' => FixtureProvider::class,
        'untapped' => UntappedProvider::class,
    ],

    'fixture_path' => env('STS_FIXTURE_PATH') ?: database_path('data'),

    'untapped' => [
        'base_url' => env('STS_UNTAPPED_BASE_URL', 'https://sts2.untapped.gg'),
        'delay_ms' => (int) env('STS_UNTAPPED_DELAY_MS', 150),
    ],

    'card_image_variant' => env('STS_CARD_IMAGE_VARIANT', 'portrait'),
];
