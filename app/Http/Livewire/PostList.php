<?php

namespace App\Http\Livewire;

use App\Models\Post;
use Illuminate\Contracts\{View\Factory, View\View};
use Illuminate\Foundation\Application;
use Livewire\Component;

class PostList extends Component
{
    public int $perPage = 5;

    public int $totals;

    public function mount()
    {
        $this->totals = Post::count();
    }

    public function load()
    {
        $this->perPage += 5;
    }

    public function render(): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        $posts = Post::with('categories')->latest()->take($this->perPage)->get();
        return view('livewire.post-list', compact('posts'));
    }
}
