<?php

namespace App\Http\Livewire\Posts;

use App\Models\Post;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;

class Featured extends Component
{
    public function render(): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        $post = Post::where('featured', true)->inRandomOrder()->first();

        if (! $post) {
            $post = Post::latest()->first();
        }

        return view('livewire.posts.featured', compact('post'));
    }
}
