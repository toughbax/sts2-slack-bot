<?php

namespace Database\Factories;

use App\Enums\EntityType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Entity>
 */
class EntityFactory extends Factory
{
    public function definition(): array
    {
        $name = ucwords(fake()->unique()->words(2, true));

        return [
            'type' => fake()->randomElement(EntityType::cases()),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'source_url' => fake()->url(),
            'metadata' => [],
            'images' => [],
            'provider' => 'factory',
        ];
    }
}
