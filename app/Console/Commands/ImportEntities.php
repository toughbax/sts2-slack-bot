<?php

namespace App\Console\Commands;

use App\Models\Entity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportEntities extends Command
{
    protected $signature = 'sts:import
        {--provider= : Provider name from config/sts.php (defaults to sts.default_provider)}
        {--save-fixtures : Write all entities back to the fixture files after importing}';

    protected $description = 'Import Slay the Spire 2 entities from a configured provider';

    public function handle(): int
    {
        $name = $this->option('provider') ?: config('sts.default_provider');
        $providers = config('sts.providers', []);

        if (! isset($providers[$name])) {
            $this->error("Unknown provider [{$name}]. Available: ".implode(', ', array_keys($providers)));

            return self::FAILURE;
        }

        $provider = $this->laravel->make($providers[$name]);

        $created = 0;
        $updated = 0;

        foreach ($provider->entities() as $imported) {
            $entity = Entity::updateOrCreate(
                ['type' => $imported->type, 'slug' => Str::slug($imported->name)],
                [
                    'name' => $imported->name,
                    'description' => $imported->description,
                    'source_url' => $imported->sourceUrl,
                    'metadata' => $imported->metadata,
                    'provider' => $provider->name(),
                ],
            );

            $entity->wasRecentlyCreated ? $created++ : $updated++;
        }

        if ($this->option('save-fixtures')) {
            $this->saveFixtures();
        }

        $this->info("Imported from [{$name}]: {$created} created, {$updated} updated.");

        return self::SUCCESS;
    }

    private function saveFixtures(): void
    {
        $path = config('sts.fixture_path');
        File::ensureDirectoryExists($path);

        Entity::query()
            ->orderBy('type')
            ->orderBy('slug')
            ->get()
            ->groupBy(fn (Entity $entity) => $entity->type->value)
            ->each(function ($entities, string $type) use ($path) {
                $records = $entities->map(fn (Entity $entity) => [
                    'type' => $entity->type->value,
                    'name' => $entity->name,
                    'description' => $entity->description,
                    'source_url' => $entity->source_url,
                    'metadata' => $entity->metadata ?? [],
                ])->values();

                $file = Str::plural($type).'.json';

                File::put(
                    "{$path}/{$file}",
                    $records->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                );

                $this->line("Wrote {$entities->count()} to {$file}");
            });
    }
}
