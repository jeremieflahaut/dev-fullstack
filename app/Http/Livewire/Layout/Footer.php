<?php

namespace App\Http\Livewire\Layout;

use Illuminate\Contracts\{View\Factory, View\View};
use Illuminate\Foundation\Application;
use Livewire\Component;

class Footer extends Component
{
    use NavTrait;

    public function render(): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.layout.footer', $this->getNavCollection());
    }
}
