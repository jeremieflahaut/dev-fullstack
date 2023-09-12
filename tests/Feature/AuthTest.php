<?php

use App\Http\Livewire\Auth\Login;
use App\Http\Livewire\Auth\LogoutConfirmationModal;
use App\Models\User;
use function Pest\Livewire\livewire;

uses()->group('auth');

it('login page', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSeeLivewire(Login::class);
});

it('register user can login', function() {
    User::factory()->create([
        'email' => 'test@test.fr'
    ]);

    livewire(Login::class)
        ->set('email', 'test@test.fr')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect('/dashboard');
});

it('not register user cannot login', function() {
    User::factory()->create([
        'email' => 'test@test.fr'
    ]);

    livewire(Login::class)
        ->set('email', 'test@test.fr')
        ->set('password', 'badpassword')
        ->call('login')
        ->assertSee('Les informations de connexion sont incorrectes.')
        ->assertNoRedirect();
});

it('register user can logout', function() {
    livewire(LogoutConfirmationModal::class)
        ->call('logout')
        ->assertRedirect('/');

    livewire(LogoutConfirmationModal::class)
        ->call('openModal')
        ->assertSet('isOpen', true);

    livewire(LogoutConfirmationModal::class)
        ->call('closeModal')
        ->assertSet('isOpen', false);
});

