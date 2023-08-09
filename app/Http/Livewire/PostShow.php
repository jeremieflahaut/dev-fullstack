<?php

namespace App\Http\Livewire;

use App\Models\Post;
use Livewire\Component;

class PostShow extends Component
{
    public $post;

    public function mount($post)
    {
        $this->post = $post;
    }

    public function render()
    {
        return view('livewire.post-show');
    }
}
