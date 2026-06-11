<?php

namespace App\Import;

use App\Enums\EntityType;

final readonly class ImportedEntity
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, string>  $images
     */
    public function __construct(
        public EntityType $type,
        public string $name,
        public string $description,
        public ?string $sourceUrl = null,
        public array $metadata = [],
        public ?string $slug = null,
        public array $images = [],
    ) {}
}
