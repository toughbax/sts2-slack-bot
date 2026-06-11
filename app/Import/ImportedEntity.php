<?php

namespace App\Import;

use App\Enums\EntityType;

final readonly class ImportedEntity
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public EntityType $type,
        public string $name,
        public string $description,
        public ?string $sourceUrl = null,
        public array $metadata = [],
    ) {}
}
