<?php

use function Pest\Livewire\livewire;

it('welcome page', function () {
    $response = $this->get('/');
    $response->assertOk();
});

it('see header component', function() {
    livewire(\App\Http\Livewire\Layout\Header::class)
        ->assertSee('Accueil');
});

it('see footer component', function() {
    livewire(\App\Http\Livewire\Layout\Footer::class)
        ->assertSee('Accueil')
        ->assertSee('Mentions Légales')
        ->assertSee('Politique de Cookies');
});
