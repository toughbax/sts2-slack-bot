<?php

namespace App\Slack;

use App\Enums\EntityType;
use App\Models\Entity;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BlockKitFormatter
{
    private const MAX_DID_YOU_MEAN = 4;

    /**
     * @return list<array<string, mixed>>
     */
    public function entityCard(Entity $entity): array
    {
        $description = $entity->description === ''
            ? '_(no description)_'
            : Str::limit($this->escapeMrkdwn($entity->description), 2999, '…');

        $isCard = $entity->type === EntityType::Card;

        $sectionBlock = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $description]];

        if (! $isCard) {
            $thumbUrl = $entity->localImageUrl('portrait');
            if ($thumbUrl !== null) {
                $sectionBlock['accessory'] = ['type' => 'image', 'image_url' => $thumbUrl, 'alt_text' => $entity->name];
            }
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => Str::limit($entity->name, 149, '…'), 'emoji' => true],
            ],
            [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => $this->contextLine($entity)]],
            ],
            $sectionBlock,
        ];

        if ($isCard) {
            $imageUrl = $this->resolveCardImageUrl($entity);
            if ($imageUrl !== null) {
                $blocks[] = ['type' => 'image', 'image_url' => $imageUrl, 'alt_text' => $entity->name];
            }
        }

        if ($entity->source_url) {
            $host = parse_url($entity->source_url, PHP_URL_HOST);
            $blocks[] = [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => "<{$entity->source_url}|View on {$host}>"]],
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    public function selectPrompt(): array
    {
        return [
            'response_type' => 'ephemeral',
            'text' => 'Search Slay the Spire 2',
            'blocks' => [[
                'type' => 'section',
                'block_id' => 'sts_search',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*Search Slay the Spire 2:* type to filter cards, relics, potions, enemies, events, and enchants.',
                ],
                'accessory' => [
                    'type' => 'external_select',
                    'action_id' => 'sts_entity_select',
                    'placeholder' => ['type' => 'plain_text', 'text' => 'Search entities…'],
                    'min_query_length' => 2,
                ],
            ]],
        ];
    }

    /**
     * @param  Collection<int, Entity>  $others
     * @return array<string, mixed>
     */
    public function searchResponse(Entity $top, Collection $others): array
    {
        $blocks = $this->entityCard($top);

        if ($others->isNotEmpty()) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => '_Did you mean:_']],
            ];
            $blocks[] = [
                'type' => 'actions',
                'block_id' => 'sts_did_you_mean',
                'elements' => $others
                    ->take(self::MAX_DID_YOU_MEAN)
                    ->map(fn (Entity $entity) => [
                        'type' => 'button',
                        'action_id' => "sts_entity_button_{$entity->id}",
                        'text' => ['type' => 'plain_text', 'text' => $this->optionLabel($entity)],
                        'value' => (string) $entity->id,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'response_type' => 'ephemeral',
            'text' => $top->name,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  Collection<int, Entity>  $entities
     * @return array<string, mixed>
     */
    public function options(Collection $entities): array
    {
        return [
            'options' => $entities
                ->map(fn (Entity $entity) => [
                    'text' => ['type' => 'plain_text', 'text' => $this->optionLabel($entity)],
                    'value' => (string) $entity->id,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function noResults(string $query): array
    {
        return [
            'response_type' => 'ephemeral',
            'text' => 'No Slay the Spire 2 entities match "'.$this->escapeMrkdwn($query).'".',
        ];
    }

    private function contextLine(Entity $entity): string
    {
        $metadata = $entity->metadata ?? [];

        $parts = array_filter([
            $entity->type->label(),
            isset($metadata['character']) ? $this->escapeMrkdwn($metadata['character']) : null,
            isset($metadata['rarity']) ? $this->escapeMrkdwn($metadata['rarity']) : null,
            isset($metadata['cost']) ? $this->escapeMrkdwn($metadata['cost']).' Cost' : null,
            isset($metadata['card_type']) ? $this->escapeMrkdwn($metadata['card_type']) : null,
        ]);

        return implode(' · ', $parts);
    }

    private function optionLabel(Entity $entity): string
    {
        return Str::limit("{$entity->name} · {$entity->type->label()}", 70, '…');
    }

    private function resolveCardImageUrl(Entity $entity): ?string
    {
        $preferred = (string) config('sts.card_image_variant', 'portrait');
        $fallback = $preferred === 'portrait' ? 'preview' : 'portrait';

        return $entity->localImageUrl($preferred) ?? $entity->localImageUrl($fallback);
    }

    private function escapeMrkdwn(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
