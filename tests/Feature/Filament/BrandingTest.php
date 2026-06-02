<?php

declare(strict_types=1);

use Filament\Facades\Filament;

it('el panel admin usa la marca FleetCore', function (): void {
    expect(Filament::getPanel('admin')->getBrandName())->toBe('FleetCore');
});

it('la página de login muestra el logo FleetCore y "by Winfin"', function (): void {
    $response = $this->get('/admin/login');

    $response->assertOk();
    // brandLogo FleetCore (Filament lo renderiza como <img src=".../images/brand/fleetcore...">)
    $response->assertSee('images/brand/fleetcore', escape: false);
    // render hook "by Winfin"
    $response->assertSee('Una solución de');
    $response->assertSee('images/brand/winfin', escape: false);
});

it('los iconos de marca (favicon/PWA) existen en public', function (): void {
    foreach ([
        'favicon.ico',
        'pwa-64x64.png',
        'pwa-192x192.png',
        'pwa-512x512.png',
        'maskable-icon-512x512.png',
        'apple-touch-icon-180x180.png',
        'images/brand/fleetcore-logo.png',
        'images/brand/fleetcore-logo-white.png',
        'images/brand/fleetcore-wordmark-white.png',
        'images/brand/winfin.png',
        'images/brand/winfin-white.png',
    ] as $asset) {
        expect(file_exists(public_path($asset)))->toBeTrue("Falta el asset: {$asset}");
    }
});

it('el manifest PWA usa el nombre FleetCore', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifest['short_name'])->toBe('FleetCore');
    expect($manifest['name'])->toContain('FleetCore');
});
