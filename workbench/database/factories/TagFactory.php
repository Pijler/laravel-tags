<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Tag;

class TagFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Tag::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->word();

        return [
            'name' => $name,
            'type' => null,
        ];
    }

    /**
     * Indicate that the tag has a specific type.
     */
    public function type(?string $type): static
    {
        return $this->state(fn () => [
            'type' => $type,
        ]);
    }
}
