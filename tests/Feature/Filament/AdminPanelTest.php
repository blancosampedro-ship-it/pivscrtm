<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers the admin panel with id "admin"', function () {
    expect(Filament::getPanel('admin'))->not->toBeNull();
});

it('admin panel uses path "admin"', function () {
    expect(Filament::getPanel('admin')->getPath())->toBe('admin');
});

it('admin panel is the default panel', function () {
    expect(Filament::getDefaultPanel()->getId())->toBe('admin');
});

it('admin panel theme points to resources/css/filament/admin/theme.css', function () {
    // Lectura estática del provider para verificar la cadena.
    $source = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
    expect($source)->toContain("'resources/css/filament/admin/theme.css'");
});

it('admin panel has primary color cobalto', function () {
    $source = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
    expect($source)->toContain("'#1D3F8C'");
});

it('login route GET /admin/login responds 200', function () {
    $this->get('/admin/login')->assertOk();
});
