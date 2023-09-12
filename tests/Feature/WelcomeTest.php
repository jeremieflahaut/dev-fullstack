<?php

use App\Http\Livewire\Layout\Footer;
use App\Http\Livewire\Layout\Header;

use function Pest\Livewire\livewire;

it('welcome page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSeeLivewire(Header::class)
        ->assertSeeLivewire(Footer::class);
});

it('see header component', function () {
    livewire(Header::class)
        ->assertSee('Accueil');
});

it('see footer component', function () {
    livewire(Footer::class)
        ->assertSee('Accueil')
        ->assertSee('Mentions Légales')
        ->assertSee('Politique de Cookies');
});
