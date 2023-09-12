<?php

namespace Database\Factories;

use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    const FOLDER = 'images/articles';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence,
            'description' => fake()->text(150),
            'content' => fake()->paragraphs(3, true),
            'image_path' => self::FOLDER.'/'.fake()->image(public_path(self::FOLDER), 400, 300, null, false),
            'featured' => fake()->boolean(),
        ];
    }
}
