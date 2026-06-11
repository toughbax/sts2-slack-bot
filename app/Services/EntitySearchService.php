<?php

namespace App\Services;

use App\Models\Entity;
use Illuminate\Support\Collection;

class EntitySearchService
{
    private const FUZZY_THRESHOLD = 0.35;

    /**
     * @return Collection<int, Entity>
     */
    public function search(string $query, int $limit = 10): Collection
    {
        $query = $this->normalize($query);

        if ($query === '') {
            return new Collection();
        }

        return Entity::query()
            ->get()
            ->map(fn (Entity $entity) => [
                'entity' => $entity,
                'score' => $this->score($query, $this->normalize($entity->name)),
            ])
            ->filter(fn (array $row) => $row['score'] > 0.0)
            ->sortBy([
                fn (array $a, array $b) => $b['score'] <=> $a['score'],
                fn (array $a, array $b) => strcmp($a['entity']->name, $b['entity']->name),
            ])
            ->take($limit)
            ->pluck('entity')
            ->values();
    }

    private function normalize(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($value)));
    }

    private function score(string $query, string $name): float
    {
        if ($name === $query) {
            return 100.0;
        }

        if (str_starts_with($name, $query)) {
            return 90.0;
        }

        if (str_contains($name, $query)) {
            return 75.0;
        }

        $similarity = $this->trigramSimilarity($query, $name);

        return $similarity >= self::FUZZY_THRESHOLD ? $similarity * 70.0 : 0.0;
    }

    private function trigramSimilarity(string $a, string $b): float
    {
        $ta = $this->trigrams($a);
        $tb = $this->trigrams($b);

        if ($ta === [] || $tb === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($ta, $tb));

        return (2 * $intersection) / (count($ta) + count($tb));
    }

    /**
     * @return list<string>
     */
    private function trigrams(string $value): array
    {
        $padded = '  '.$value.' ';
        $grams = [];

        for ($i = 0, $max = mb_strlen($padded) - 2; $i < $max; $i++) {
            $grams[] = mb_substr($padded, $i, 3);
        }

        return array_values(array_unique($grams));
    }
}
