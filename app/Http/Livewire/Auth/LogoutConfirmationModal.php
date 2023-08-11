<?php

namespace App\Http\Livewire\Auth;

use Illuminate\Contracts\{View\Factory, View\View};
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Livewire\Component;

class LogoutConfirmationModal extends Component
{
    /**
     * @var bool
     */
    public bool $isOpen = false;

    /**
     * @return void
     */
    public function openModal(): void
    {
        $this->isOpen = true;
    }

    /**
     * @return void
     */
    public function closeModal(): void
    {
        $this->isOpen = false;
    }

    /**
     * @return Application|Redirector|RedirectResponse|\Illuminate\Contracts\Foundation\Application
     */
    public function logout(): Application|Redirector|RedirectResponse|\Illuminate\Contracts\Foundation\Application
    {
        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->isOpen = false;
        return redirect()->route('home');
    }

    /**
     * @return View|Application|Factory|\Illuminate\Contracts\Foundation\Application
     */
    public function render(): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.auth.logout-confirmation-modal');
    }
}
