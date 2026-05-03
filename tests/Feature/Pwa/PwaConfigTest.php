<?php

declare(strict_types=1);

use Livewire\Volt\Volt;

it('manifest_webmanifest_exists_with_json_content', function (): void {
    $path = public_path('manifest.webmanifest');

    expect($path)->toBeFile();
    expect(json_decode(file_get_contents($path), true))->toBeArray();
});

it('manifest_has_required_pwa_fields', function (): void {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifest['name'])->toBe('Winfin PIV - Técnico');
    expect($manifest['short_name'])->toBe('Winfin PIV');
    expect($manifest['start_url'])->toBe('/tecnico');
    expect($manifest['scope'])->toBe('/tecnico/');
    expect($manifest['display'])->toBe('standalone');
    expect($manifest['theme_color'])->toBe('#0F62FE');
    expect($manifest['id'])->toBe('/tecnico');
});

it('manifest_icons_have_192_512_and_maskable', function (): void {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
    $icons = collect($manifest['icons']);

    expect($icons->firstWhere('sizes', '192x192'))->toMatchArray([
        'src' => '/pwa-192x192.png',
        'type' => 'image/png',
        'purpose' => 'any',
    ]);
    expect($icons->firstWhere('sizes', '512x512'))->not->toBeNull();
    expect($icons->firstWhere('purpose', 'maskable'))->toMatchArray([
        'src' => '/maskable-icon-512x512.png',
        'sizes' => '512x512',
        'type' => 'image/png',
    ]);
});

it('apple_touch_icon_180_exists', function (): void {
    expect(public_path('apple-touch-icon-180x180.png'))->toBeFile();
    expect(filesize(public_path('apple-touch-icon-180x180.png')))->toBeGreaterThan(0);
});

it('pwa_icons_192_and_512_exist', function (): void {
    expect(public_path('pwa-192x192.png'))->toBeFile();
    expect(public_path('pwa-512x512.png'))->toBeFile();
    expect(public_path('maskable-icon-512x512.png'))->toBeFile();
});

it('tecnico_shell_includes_apple_touch_icon_link', function (): void {
    $this->get('/tecnico/login')
        ->assertOk()
        ->assertSee('<link rel="apple-touch-icon" sizes="180x180" href="http://localhost/apple-touch-icon-180x180.png">', false);
});

it('tecnico_shell_includes_apple_mobile_web_app_meta_tags', function (): void {
    $this->get('/tecnico/login')
        ->assertOk()
        ->assertSee('<meta name="apple-mobile-web-app-capable" content="yes">', false)
        ->assertSee('<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">', false)
        ->assertSee('<meta name="apple-mobile-web-app-title" content="Winfin PIV">', false);
});

it('tecnico_shell_includes_theme_color_meta', function (): void {
    $this->get('/tecnico/login')
        ->assertOk()
        ->assertSee('<meta name="theme-color" content="#0F62FE">', false);
});

it('pwa_update_banner_renders_hidden_by_default', function (): void {
    Volt::test('tecnico.pwa-update-banner')
        ->assertSet('show', false)
        ->assertSee('style="display:none"', false)
        ->assertSee('Nueva versión disponible');
});

it('pwa_update_banner_shows_when_pwa_update_available_event_fires', function (): void {
    Volt::test('tecnico.pwa-update-banner')
        ->dispatch('pwa:update-available')
        ->assertSet('show', true)
        ->assertDontSee('style="display:none"', false);
});

it('vite_pwa_uses_prompt_strategy_not_autoupdate', function (): void {
    $config = file_get_contents(base_path('vite.config.js'));

    expect($config)->toContain("registerType: 'prompt'");
    expect($config)->not->toContain("registerType: 'autoUpdate'");
});

it('vite_pwa_keeps_livewire_network_only', function (): void {
    $config = file_get_contents(base_path('vite.config.js'));

    expect($config)->toContain('urlPattern: /\\/livewire\\/.*$/');
    expect($config)->toContain("handler: 'NetworkOnly'");
});

it('vite_pwa_does_not_generate_a_second_manifest', function (): void {
    expect(file_get_contents(base_path('vite.config.js')))->toContain('manifest: false');
});
