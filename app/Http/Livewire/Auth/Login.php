<?php

namespace App\Http\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Login extends Component
{
    public $email;

    public $password;

    protected $rules = [
        'email' => 'required|email',
        'password' => 'required',
    ];

    public function login()
    {
        $this->validate();

        $credentials = [
            'email' => $this->email,
            'password' => $this->password,
        ];

        if (Auth::attempt($credentials)) {
            // Connexion réussie, rediriger vers la page souhaitée
            return redirect()->intended('/dashboard');
        }

        // En cas d'échec de connexion, afficher un message d'erreur
        session()->flash('error', 'Les informations de connexion sont incorrectes.');
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
