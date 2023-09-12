<?php

declare(strict_types=1);

namespace App\Http\Livewire\Posts;

use App\Models\Post;
use Livewire\Component;

class Show extends Component
{
    public Post $post;

    public function mount($post)
    {
        $this->post = $post;
    }

    public function render()
    {
        return view('livewire.posts.show');
    }
}
