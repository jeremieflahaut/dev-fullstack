<?php

use function Pest\Livewire\livewire;

it('test welcome Page', function () {
    $response = $this->get('/');
    $response->assertOk();
});

it('see header', function() {
    livewire(\App\Http\Livewire\Layout\Header::class)
        ->assertSee('Accueil');
});

it('see footer', function() {
    livewire(\App\Http\Livewire\Layout\Footer::class)
        ->assertSee('Accueil')
        ->assertSee('Mentions Légales')
        ->assertSee('Politique de Cookies');
});
