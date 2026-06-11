<?php

namespace App\Import;

interface ImportProvider
{
    public function name(): string;

    /**
     * @return iterable<ImportedEntity>
     */
    public function entities(): iterable;
}
