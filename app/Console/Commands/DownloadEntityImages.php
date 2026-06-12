<?php

namespace App\Console\Commands;

use App\Enums\EntityType;
use App\Models\Entity;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
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
                $path = $entity->imagePath($variant);

                if (! $this->option('force') && $disk->exists($path)) {
                    $skipped++;

                    continue;
                }

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }

                try {
                    $response = Http::timeout(30)->get($url);
                } catch (ConnectionException $e) {
                    $failed[] = "{$entity->type->value}/{$entity->slug} {$variant}: {$url} ({$e->getMessage()})";

                    continue;
                }

                if ($response->failed()) {
                    $failed[] = "{$entity->type->value}/{$entity->slug} {$variant}: {$url} ({$response->status()})";

                    continue;
                }

                $disk->put($path, $response->body());
                $downloaded++;
            }
        }

        $composed = $this->composeComparisons($disk, $failed);

        $this->info("Images: {$downloaded} downloaded, {$skipped} skipped, {$composed} composed, ".count($failed).' failed.');

        foreach ($failed as $line) {
            $this->warn($line);
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $failed
     */
    private function composeComparisons(Filesystem $disk, array &$failed): int
    {
        if (! function_exists('imagecreatefromwebp')) {
            $this->warn('GD webp support missing; skipping comparison images.');

            return 0;
        }

        $composed = 0;

        foreach (Entity::query()->where('type', EntityType::Card)->get() as $entity) {
            $basePath = $entity->imagePath('preview');
            $upgradedPath = $entity->imagePath('preview_upgraded');

            if ($basePath === null || $upgradedPath === null) {
                continue;
            }

            $targetPath = "images/card/{$entity->slug}-comparison.png";

            if (! $this->option('force') && $disk->exists($targetPath)) {
                continue;
            }

            if (! $disk->exists($basePath) || ! $disk->exists($upgradedPath)) {
                continue;
            }

            $base = $this->readImage($disk->get($basePath), $basePath);
            $upgraded = $this->readImage($disk->get($upgradedPath), $upgradedPath);

            if ($base === null || $upgraded === null) {
                $failed[] = "card/{$entity->slug} comparison: unreadable source image";

                continue;
            }

            $gutter = 16;
            $height = max(imagesy($base), imagesy($upgraded));
            $width = imagesx($base) + $gutter + imagesx($upgraded);

            $canvas = imagecreatetruecolor($width, $height);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagealphablending($canvas, true);

            imagecopy($canvas, $base, 0, 0, 0, 0, imagesx($base), imagesy($base));
            imagecopy($canvas, $upgraded, imagesx($base) + $gutter, 0, 0, 0, imagesx($upgraded), imagesy($upgraded));

            ob_start();
            imagepng($canvas);
            $disk->put($targetPath, (string) ob_get_clean());
            $composed++;
        }

        return $composed;
    }

    private function readImage(string $bytes, string $path): ?\GdImage
    {
        $image = str_ends_with($path, '.webp')
            ? @imagecreatefromwebp('data://application/octet-stream;base64,'.base64_encode($bytes))
            : @imagecreatefromstring($bytes);

        return $image instanceof \GdImage ? $image : null;
    }
}
