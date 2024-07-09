<?php

declare(strict_types=1);

use App\Http\Livewire\Posts\Index;
use App\Models\Post;
use Livewire\Livewire;

uses()->group('posts');

test('initializes totals correctly in mount method', function () {

    $posts = Post::factory(5)->create();

    Livewire::test(Index::class)
        ->assertSet('totals', $posts->count());

});

test('increases perPage in load method', function () {
    $component = Livewire::test(Index::class);
    $initialPerPage = $component->get('perPage');

    $component->call('load')
        ->assertSet('perPage', $initialPerPage + 5);
});

test('renders the view with correct data', function () {
    Post::factory(5)->create();

    Livewire::test(Index::class)
        ->assertViewHas('posts', function ($posts) {
            return count($posts) === 5;
        });
});
