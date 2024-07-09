<?php

declare(strict_types=1);

use App\Http\Livewire\Posts\Featured;
use App\Models\Post;
use Livewire\Livewire;

uses()->group('posts');

test('renders the view with correct data', function () {
    $featuredPost = Post::factory()->create([
        'featured' => true,
        'created_at' => now()->subDays(3),
    ]);

    Post::factory()->create([
        'created_at' => now()->subDays(2),
        'featured' => false,
    ]);

    $latestPost = Post::factory()->create([
        'created_at' => now(),
        'featured' => false,
    ]);

    Livewire::test(Featured::class)
        ->assertViewHas('post', function ($post) use ($featuredPost) {
            return $post->id === $featuredPost->id;
        });

    Post::where('featured', true)->delete();

    Livewire::test(Featured::class)
        ->assertViewHas('post', function ($post) use ($latestPost) {
            return $post->id === $latestPost->id;
        });
});
