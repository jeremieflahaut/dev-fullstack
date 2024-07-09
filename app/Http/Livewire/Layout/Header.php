<?php

declare(strict_types=1);

namespace App\Http\Livewire\Layout;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;

class Header extends Component
{
    use NavTrait;

    public function render(): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.layout.header', $this->getNavCollection());
    }
}
