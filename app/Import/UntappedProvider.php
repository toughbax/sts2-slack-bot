<?php

namespace App\Import;

use App\Enums\EntityType;
use Illuminate\Support\Facades\Http;

final class UntappedProvider implements ImportProvider
{
    private const SECTIONS = [
        'cards' => EntityType::Card,
        'relics' => EntityType::Relic,
        'potions' => EntityType::Potion,
        'events' => EntityType::Event,
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $delayMs,
    ) {}

    public function name(): string
    {
        return 'untapped';
    }

    public function entities(): iterable
    {
        foreach (self::SECTIONS as $section => $type) {
            foreach ($this->detailUrls($section) as $url) {
                if ($this->delayMs > 0) {
                    usleep($this->delayMs * 1000);
                }

                $entity = $this->parsePage($type, $url);

                if ($entity !== null) {
                    yield $entity;
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function detailUrls(string $section): array
    {
        $xml = Http::timeout(30)->get("{$this->baseUrl}/sitemap/{$section}.xml")->throw()->body();

        preg_match_all(
            '#<loc>('.preg_quote($this->baseUrl, '#')."/en/{$section}/[a-z0-9-]+)</loc>#",
            $xml,
            $matches,
        );

        return array_values(array_unique($matches[1]));
    }

    private function parsePage(EntityType $type, string $url): ?ImportedEntity
    {
        $response = Http::timeout(30)->get($url);

        if ($response->failed()) {
            return null;
        }

        $html = $response->body();
        $name = $this->extractName($html);
        $description = $this->extractDescription($html);

        if ($name === null || $description === null) {
            return null;
        }

        [$description, $metadata] = $this->parseDescription($type, $description);

        if ($type === EntityType::Card) {
            $upgraded = $this->extractUpgradedDescription($html);

            if ($upgraded !== null) {
                $metadata['upgraded_description'] = $upgraded;
            }
        }

        $slug = basename(parse_url($url, PHP_URL_PATH));

        return new ImportedEntity($type, $name, $description, $url, $metadata, $slug, images: $this->extractImages($html, $slug));
    }

    private function extractName(string $html): ?string
    {
        if (! preg_match('/<title>([^<]+)<\/title>/u', $html, $m)) {
            return null;
        }

        $name = trim(explode('–', html_entity_decode($m[1], ENT_QUOTES))[0]);

        // Titles carry classification suffixes like "(Event)", "(Shop Relic)",
        // "(Common Potion)" — noise we already capture as metadata.
        return preg_replace('/\s*\([^)]*\)$/', '', $name) ?: null;
    }

    private function extractDescription(string $html): ?string
    {
        if (preg_match('/<meta name="description" content="([^"]*)"/u', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }

        // Next.js flight payload fallback: the meta element is JSON-escaped inside
        // a script string as \"name\":\"description\",\"content\":\"…\"
        if (preg_match('/\\\\"name\\\\":\\\\"description\\\\",\\\\"content\\\\":\\\\"((?:[^"\\\\]|\\\\.)*?)\\\\"/su', $html, $m)) {
            $decoded = json_decode('"'.str_replace('\\\\', '\\', $m[1]).'"');

            return is_string($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function extractImages(string $html, string $slug): array
    {
        // Art basenames mix separators ("mad_science-chaos.png" for page slug
        // "mad-science-chaos"), so each separator position matches - or _.
        $flexibleSlug = implode('[-_]', array_map(
            fn (string $part) => preg_quote($part, '#'),
            preg_split('/-/', $slug) ?: [],
        ));

        $images = [];

        if (preg_match(
            '#https://sts2json\.untapped\.gg/art/[a-z0-9_/.-]+/'.$flexibleSlug.'\.png#',
            $html,
            $m,
        )) {
            $images['portrait'] = $m[0];
        }

        // Previews are usually .webp but exist as .png for some cards.
        if (preg_match(
            '#https://img-preview\.untapped\.gg/[a-z0-9_/.-]+/'.preg_quote($slug, '#').'\.(?:webp|png)#',
            $html,
            $m,
        )) {
            $images['preview'] = $m[0];
        }

        return $images;
    }

    private function extractUpgradedDescription(string $html): ?string
    {
        if (! preg_match('/__upgradeDetails[^>]*>(.*?)<\/div>/su', $html, $m)) {
            return null;
        }

        $text = preg_replace('/<img[^>]*\salt="([^"]*)"[^>]*>/u', ' $1 ', $m[1]);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES);
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return $text !== '' ? $text : null;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function parseDescription(EntityType $type, string $description): array
    {
        $pattern = match ($type) {
            EntityType::Card => '/^.+? is an? (?<cost>\d+|X)-Cost (?<rarity>[A-Za-z]+) (?<card_type>[A-Za-z]+) card in the (?<character>[A-Za-z\' ]+) pool: (?<desc>.+)$/su',
            EntityType::Relic => '/^.+? is an? (?<rarity>[A-Za-z]+) relic in the (?<character>[A-Za-z\' ]+) pool: (?<desc>.+)$/su',
            EntityType::Potion => '/^.+? is an? (?<rarity>[A-Za-z]+) potion in the (?<character>[A-Za-z\' ]+) pool: (?<desc>.+)$/su',
            default => null,
        };

        if ($pattern === null || ! preg_match($pattern, $description, $m)) {
            return [$description, []];
        }

        $metadata = array_filter([
            'cost' => $m['cost'] ?? null,
            'rarity' => $m['rarity'] ?? null,
            'card_type' => $m['card_type'] ?? null,
            'character' => $m['character'] ?? null,
        ], fn (?string $value) => $value !== null && $value !== '');

        return [trim($m['desc']), $metadata];
    }
}
