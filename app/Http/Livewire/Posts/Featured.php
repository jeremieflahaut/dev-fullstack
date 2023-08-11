<?php

namespace App\Http\Livewire\Posts;

use App\Models\Post;
use Livewire\Component;

class Featured extends Component
{
    public function render()
    {
        $post = Post::inRandomOrder()->first();

        return view('livewire.posts.featured', compact('post'));
    }
}
