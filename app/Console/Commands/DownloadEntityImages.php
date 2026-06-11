<?php

namespace App\Console\Commands;

use App\Models\Entity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DownloadEntityImages extends Command
{
    protected $signature = 'sts:images
        {--force : Re-download images that already exist locally}';

    protected $description = 'Download entity images from their remote URLs to the public disk';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $delayMs = (int) config('sts.untapped.delay_ms');

        $downloaded = 0;
        $skipped = 0;
        $failed = [];

        foreach (Entity::query()->whereNotNull('images')->get() as $entity) {
            foreach ($entity->images ?? [] as $variant => $url) {
                $extension = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';
                $path = "images/{$entity->type->value}/{$entity->slug}-{$variant}.{$extension}";

                if (! $this->option('force') && $disk->exists($path)) {
                    $skipped++;

                    continue;
                }

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                $response = Http::timeout(30)->get($url);

                if ($response->failed()) {
                    $failed[] = "{$entity->type->value}/{$entity->slug} {$variant}: {$url} ({$response->status()})";

                    continue;
                }

                $disk->put($path, $response->body());
                $downloaded++;
            }
        }

        $this->info("Images: {$downloaded} downloaded, {$skipped} skipped, ".count($failed).' failed.');

        foreach ($failed as $line) {
            $this->warn($line);
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
