<?php

declare(strict_types=1);

use App\Http\Livewire\Posts\Index;
use App\Http\Livewire\Posts\Show;
use App\Models\Post;

uses()->group('posts');

it('posts page', function () {
    Post::factory(3)->create();

    $this->get('/articles')
        ->assertOk()
        ->assertSeeLivewire(Index::class);
});

it('single post page', function () {
    $post = Post::factory()->create();

    $this->get('/articles/'.$post->slug)
        ->assertOk()
        ->assertSeeLivewire(Show::class);
});
