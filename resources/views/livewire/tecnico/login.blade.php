<?php

use App\Auth\LegacyHashGuard;
use App\Models\Tecnico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use function Livewire\Volt\layout;
use function Livewire\Volt\title;

layout('components.tecnico.shell');
title('Winfin PIV - Técnico');

new class extends Component {
    public string $email = '';

    public string $password = '';

    public function login(LegacyHashGuard $guard, Request $request): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $ok = $guard->attempt($this->email, $this->password, 'tecnico', $request);

        if (! $ok) {
            throw ValidationException::withMessages([
                'email' => 'Credenciales no válidas.',
            ]);
        }

        $user = Auth::user();
        $tecnico = $user?->legacyEntity();
        if (! $tecnico instanceof Tecnico || (int) $tecnico->status !== 1) {
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages([
                'email' => 'Cuenta de técnico inactiva. Contacta con admin.',
            ]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->redirect(route('tecnico.dashboard'), navigate: false);
    }
}; ?>

<div class="min-h-[calc(100vh-3.5rem)] flex flex-col items-center justify-center p-6">
    <div class="w-full max-w-sm">
        <h1 class="text-xl font-medium mb-6 text-center">Acceso técnico</h1>

        <form wire:submit="login" class="space-y-4">
            <div>
                <label for="email" class="block text-xs font-normal text-ink-secondary mb-1 tracking-wider">Email</label>
                <input type="email"
                       id="email"
                       wire:model="email"
                       autocomplete="username"
                       required
                       class="w-full bg-layer-1 text-ink-primary border-0 border-b-2 border-transparent focus:border-primary-60 focus:outline-none px-3 py-3 text-md">
                @error('email') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password" class="block text-xs font-normal text-ink-secondary mb-1 tracking-wider">Contraseña</label>
                <input type="password"
                       id="password"
                       wire:model="password"
                       autocomplete="current-password"
                       required
                       class="w-full bg-layer-1 text-ink-primary border-0 border-b-2 border-transparent focus:border-primary-60 focus:outline-none px-3 py-3 text-md">
                @error('password') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>

            <button type="submit"
                    class="tap-target w-full bg-primary-60 hover:bg-primary-70 text-ink-on_color font-medium text-md transition-colors duration-fast-01 ease-carbon-productive">
                <span wire:loading.remove wire:target="login">Entrar</span>
                <span wire:loading wire:target="login">Verificando...</span>
            </button>
        </form>
    </div>
</div>
