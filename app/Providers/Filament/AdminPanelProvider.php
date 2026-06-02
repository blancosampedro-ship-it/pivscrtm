<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Widgets\AsignacionesAveriasStatsOverview;
use App\Filament\Widgets\CargaPorTecnicoWidget;
use App\Filament\Widgets\TopPanelesIncidenciaWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            // Marca: producto = FleetCore, empresa = Winfin (DESIGN.md §3 / log 2026-06-02).
            // brandLogo a color → se ve a color en el login; en la barra del panel se desatura
            // a mono vía theme.css (.fi-topbar/.fi-sidebar .fi-logo) para no romper la densidad Carbon.
            ->brandName('FleetCore')
            ->brandLogo(asset('images/brand/fleetcore-logo.png'))
            ->darkModeBrandLogo(asset('images/brand/fleetcore-logo-white.png'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('favicon.ico'))
            // "by Winfin" bajo el formulario de login (la empresa detrás del producto).
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): string => Blade::render('<x-brand.by-winfin />'),
            )
            ->colors([
                // Carbon Blue 60 — único acento (DESIGN.md §4). Sustituye al cobalto legacy '#1D3F8C'.
                'primary' => Color::hex('#0F62FE'),
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                AsignacionesAveriasStatsOverview::class,
                TopPanelesIncidenciaWidget::class,
                CargaPorTecnicoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
