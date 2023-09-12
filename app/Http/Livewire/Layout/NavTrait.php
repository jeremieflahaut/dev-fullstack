<?php

namespace App\Http\Livewire\Layout;

use Illuminate\Support\Collection;

trait NavTrait
{
    protected array $nav = [
        [
            'route' => 'home',
            'text' => 'Accueil',
            'auth' => false,
        ],
        [
            'route' => 'posts.index',
            'text' => 'Blog',
            'auth' => false,
        ],
        [
            'route' => 'dashboard',
            'text' => 'Dashboard',
            'auth' => true,
        ],
    ];

    protected Collection $navCollection;

    public function mount(): void
    {
        $this->navCollection = collect($this->nav);
    }

    protected function getNavCollection(): array
    {
        $nav = $this->navCollection->where('auth', false)->toArray();
        $auth = $this->navCollection->where('auth', true)->toArray();

        return compact('nav', 'auth');
    }
}
