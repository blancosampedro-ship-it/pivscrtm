<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('la raiz lleva al panel cuando no hay sesion', function () {
    $this->get('/')->assertRedirect('/admin');
});

it('la raiz lleva al panel para un admin', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/')->assertRedirect('/admin');
});

it('la raiz lleva a la PWA para un tecnico', function () {
    $this->actingAs(User::factory()->tecnico()->create());

    $this->get('/')->assertRedirect(route('tecnico.dashboard'));
});
