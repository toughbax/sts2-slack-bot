<?php

namespace App\Import;

use App\Enums\EntityType;

final class FixtureProvider implements ImportProvider
{
    public function __construct(private readonly string $path) {}

    public function name(): string
    {
        return 'fixture';
    }

    public function entities(): iterable
    {
        foreach (glob($this->path.'/*.json') ?: [] as $file) {
            $records = json_decode(
                (string) file_get_contents($file),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );

            foreach ($records as $record) {
                yield new ImportedEntity(
                    type: EntityType::from($record['type']),
                    name: $record['name'],
                    description: $record['description'],
                    sourceUrl: $record['source_url'] ?? null,
                    metadata: $record['metadata'] ?? [],
                    slug: $record['slug'] ?? null,
                    images: $record['images'] ?? [],
                );
            }
        }
    }
}
