<?php

use App\Http\Controllers\Tecnico\LogoutController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// La app no tiene home propia: vive en /admin (Filament) y /tecnico (PWA).
// Un técnico logueado va a su PWA; el resto al panel, que ya redirige a login
// si hace falta.
Route::get('/', function () {
    return auth()->user()?->isTecnico()
        ? redirect()->route('tecnico.dashboard')
        : redirect('/admin');
})->name('home');

Volt::route('/tecnico/login', 'tecnico.login')->name('tecnico.login');

Route::middleware('tecnico')->prefix('tecnico')->name('tecnico.')->group(function (): void {
    Volt::route('/', 'tecnico.dashboard')->name('dashboard');
    Volt::route('/asignaciones/{asignacion}', 'tecnico.cierre')->name('asignacion.cierre');
    Volt::route('/ruta-item/{itemId}/cierre', 'tecnico.ruta-item-cierre')->name('ruta-item.cierre');
    Route::post('/logout', LogoutController::class)->name('logout');
});
