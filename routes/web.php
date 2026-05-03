<?php

use App\Http\Controllers\Tecnico\LogoutController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
});

Volt::route('/tecnico/login', 'tecnico.login')->name('tecnico.login');

Route::middleware('tecnico')->prefix('tecnico')->name('tecnico.')->group(function (): void {
    Volt::route('/', 'tecnico.dashboard')->name('dashboard');
    Volt::route('/asignaciones/{asignacion}', 'tecnico.cierre')->name('asignacion.cierre');
    Route::post('/logout', LogoutController::class)->name('logout');
});
