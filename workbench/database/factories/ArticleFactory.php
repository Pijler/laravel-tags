<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Article;

class ArticleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Article::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(),
        ];
    }
}
