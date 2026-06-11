<?php

use App\Enums\EntityType;
use App\Import\UntappedProvider;
use Illuminate\Support\Facades\Http;

const BASE = 'https://sts2.test';

function sitemapXml(string $section, array $slugs): string
{
    $urls = collect($slugs)
        ->map(fn (string $slug) => '<url><loc>'.BASE."/en/{$section}/{$slug}</loc></url>")
        ->implode('');

    return '<?xml version="1.0" encoding="UTF-8"?><urlset>'.$urls.'</urlset>';
}

function detailHtml(string $title, ?string $metaDescription, ?string $flightDescription = null): string
{
    $meta = $metaDescription !== null
        ? '<meta name="description" content="'.e($metaDescription).'"/>'
        : '';
    $flight = '';
    if ($flightDescription !== null) {
        // Build the flight payload the way Next.js does: a JSON-encoded string
        // pushed into self.__next_f, containing escaped meta JSON.
        $inner = json_encode([['name' => 'description', 'content' => $flightDescription]]);
        $script = json_encode($inner); // double-encode: escapes the quotes like the real payload
        $flight = '<script>self.__next_f.push([1,'.$script.'])</script>';
    }

    return "<html><head><title>{$title}</title>{$meta}</head><body>{$flight}</body></html>";
}

beforeEach(function () {
    $this->provider = new UntappedProvider(baseUrl: BASE, delayMs: 0);
});

it('imports a card with parsed metadata', function () {
    Http::fake([
        BASE.'/sitemap/cards.xml' => Http::response(sitemapXml('cards', ['ball-lightning'])),
        BASE.'/sitemap/relics.xml' => Http::response(sitemapXml('relics', [])),
        BASE.'/sitemap/potions.xml' => Http::response(sitemapXml('potions', [])),
        BASE.'/sitemap/events.xml' => Http::response(sitemapXml('events', [])),
        BASE.'/en/cards/ball-lightning' => Http::response(detailHtml(
            'Ball Lightning – Defect Common Attack – Slay the Spire 2 Card – Untapped.gg',
            'Ball Lightning is a 1-Cost Common Attack card in the Defect pool: Deal 7 damage. Channel 1 Lightning.',
        )),
    ]);

    $entities = collect($this->provider->entities());

    expect($entities)->toHaveCount(1)
        ->and($entities->first())
        ->type->toBe(EntityType::Card)
        ->name->toBe('Ball Lightning')
        ->slug->toBe('ball-lightning')
        ->description->toBe('Deal 7 damage. Channel 1 Lightning.')
        ->sourceUrl->toBe(BASE.'/en/cards/ball-lightning')
        ->metadata->toBe([
            'cost' => '1',
            'rarity' => 'Common',
            'card_type' => 'Attack',
            'character' => 'Defect',
        ]);
});

it('imports a relic and a potion with parsed metadata', function () {
    Http::fake([
        BASE.'/sitemap/cards.xml' => Http::response(sitemapXml('cards', [])),
        BASE.'/sitemap/relics.xml' => Http::response(sitemapXml('relics', ['akabeko'])),
        BASE.'/sitemap/potions.xml' => Http::response(sitemapXml('potions', ['ashwater'])),
        BASE.'/sitemap/events.xml' => Http::response(sitemapXml('events', [])),
        BASE.'/en/relics/akabeko' => Http::response(detailHtml(
            'Akabeko – Slay the Spire 2 Relic – Untapped.gg',
            'Akabeko is a Uncommon relic in the Colorless pool: At the start of each combat, gain 8 Vigor.',
        )),
        BASE.'/en/potions/ashwater' => Http::response(detailHtml(
            'Ashwater – Slay the Spire 2 Potion – Untapped.gg',
            'Ashwater is a Uncommon potion in the Ironclad pool: Exhaust any number of cards in your Hand.',
        )),
    ]);

    $entities = collect($this->provider->entities());

    expect($entities)->toHaveCount(2)
        ->and($entities->firstWhere('name', 'Akabeko'))
        ->type->toBe(EntityType::Relic)
        ->description->toBe('At the start of each combat, gain 8 Vigor.')
        ->metadata->toBe(['rarity' => 'Uncommon', 'character' => 'Colorless'])
        ->and($entities->firstWhere('name', 'Ashwater'))
        ->type->toBe(EntityType::Potion)
        ->metadata->toBe(['rarity' => 'Uncommon', 'character' => 'Ironclad']);
});

it('strips classification suffixes from names', function () {
    Http::fake([
        BASE.'/sitemap/cards.xml' => Http::response(sitemapXml('cards', [])),
        BASE.'/sitemap/relics.xml' => Http::response(sitemapXml('relics', ['toolbox'])),
        BASE.'/sitemap/potions.xml' => Http::response(sitemapXml('potions', [])),
        BASE.'/sitemap/events.xml' => Http::response(sitemapXml('events', [])),
        BASE.'/en/relics/toolbox' => Http::response(detailHtml(
            'Toolbox (Shop Relic) – Slay the Spire 2 Relic – Untapped.gg',
            'Toolbox is a Shop relic in the Colorless pool: At the start of each combat, choose 1 of 3 random Colorless cards.',
        )),
    ]);

    expect(collect($this->provider->entities())->first())
        ->name->toBe('Toolbox')
        ->metadata->toBe(['rarity' => 'Shop', 'character' => 'Colorless']);
});

it('imports an event from the flight payload', function () {
    Http::fake([
        BASE.'/sitemap/cards.xml' => Http::response(sitemapXml('cards', [])),
        BASE.'/sitemap/relics.xml' => Http::response(sitemapXml('relics', [])),
        BASE.'/sitemap/potions.xml' => Http::response(sitemapXml('potions', [])),
        BASE.'/sitemap/events.xml' => Http::response(sitemapXml('events', ['abyssal-baths'])),
        BASE.'/en/events/abyssal-baths' => Http::response(detailHtml(
            'Abyssal Baths (Event) – Slay the Spire 2 – Untapped.gg',
            null,
            'You discover a secluded chamber.',
        )),
    ]);

    $entities = collect($this->provider->entities());

    expect($entities)->toHaveCount(1)
        ->and($entities->first())
        ->type->toBe(EntityType::Event)
        ->name->toBe('Abyssal Baths')
        ->description->toBe('You discover a secluded chamber.')
        ->metadata->toBe([]);
});

it('skips pages that fail or cannot be parsed', function () {
    Http::fake([
        BASE.'/sitemap/cards.xml' => Http::response(sitemapXml('cards', ['broken', 'missing'])),
        BASE.'/sitemap/relics.xml' => Http::response(sitemapXml('relics', [])),
        BASE.'/sitemap/potions.xml' => Http::response(sitemapXml('potions', [])),
        BASE.'/sitemap/events.xml' => Http::response(sitemapXml('events', [])),
        BASE.'/en/cards/broken' => Http::response('<html><head><title>Broken</title></head></html>'),
        BASE.'/en/cards/missing' => Http::response('', 404),
    ]);

    expect(collect($this->provider->entities()))->toBeEmpty();
});

it('falls back gracefully when the description prefix does not parse', function () {
    Http::fake([
        BASE.'/sitemap/cards.xml' => Http::response(sitemapXml('cards', ['weird'])),
        BASE.'/sitemap/relics.xml' => Http::response(sitemapXml('relics', [])),
        BASE.'/sitemap/potions.xml' => Http::response(sitemapXml('potions', [])),
        BASE.'/sitemap/events.xml' => Http::response(sitemapXml('events', [])),
        BASE.'/en/cards/weird' => Http::response(detailHtml(
            'Weird Card – Untapped.gg',
            'Some unstructured description text.',
        )),
    ]);

    $entities = collect($this->provider->entities());

    expect($entities->first())
        ->name->toBe('Weird Card')
        ->description->toBe('Some unstructured description text.')
        ->metadata->toBe([]);
});

it('keeps url slugs for entities with colliding display names', function () {
    Http::fake([
        BASE.'/sitemap/cards.xml' => Http::response(sitemapXml('cards', ['strike-ironclad', 'strike-silent'])),
        BASE.'/sitemap/relics.xml' => Http::response(sitemapXml('relics', [])),
        BASE.'/sitemap/potions.xml' => Http::response(sitemapXml('potions', [])),
        BASE.'/sitemap/events.xml' => Http::response(sitemapXml('events', [])),
        BASE.'/en/cards/strike-ironclad' => Http::response(detailHtml(
            'Strike – Ironclad Starter Attack – Slay the Spire 2 Card – Untapped.gg',
            'Strike is a 1-Cost Starter Attack card in the Ironclad pool: Deal 6 damage.',
        )),
        BASE.'/en/cards/strike-silent' => Http::response(detailHtml(
            'Strike – Silent Starter Attack – Slay the Spire 2 Card – Untapped.gg',
            'Strike is a 1-Cost Starter Attack card in the Silent pool: Deal 6 damage.',
        )),
    ]);

    $entities = collect($this->provider->entities());

    expect($entities)->toHaveCount(2)
        ->and($entities->pluck('slug')->all())->toBe(['strike-ironclad', 'strike-silent']);
});
