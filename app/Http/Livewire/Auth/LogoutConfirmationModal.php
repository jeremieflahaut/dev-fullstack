<?php

namespace App\Http\Livewire\Auth;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Livewire\Component;

class LogoutConfirmationModal extends Component
{
    public bool $isOpen = false;

    public function openModal(): void
    {
        $this->isOpen = true;
    }

    public function closeModal(): void
    {
        $this->isOpen = false;
    }

    public function logout(): Application|Redirector|RedirectResponse|\Illuminate\Contracts\Foundation\Application
    {
        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->isOpen = false;

        return redirect()->route('home');
    }

    public function render(): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.auth.logout-confirmation-modal');
    }
}
