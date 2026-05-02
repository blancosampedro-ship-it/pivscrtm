<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0F62FE">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <title>{{ $title ?? 'Winfin PIV - Técnico' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-layer-0 text-ink-primary min-h-screen font-sans antialiased">
    <header class="bg-ink-primary text-ink-on_color flex items-center justify-between px-4 h-14 sticky top-0 z-10">
        <a href="{{ route('tecnico.dashboard') }}" class="brand text-base font-semibold tracking-tight">
            Win<em>f</em>in <strong>PIV</strong>
        </a>
        @if (auth()->user()?->isTecnico())
            <div class="flex items-center gap-3">
                <span class="text-sm text-ink-on_color/80 truncate max-w-36">{{ auth()->user()->name }}</span>
                <form action="{{ route('tecnico.logout') }}" method="POST">
                    @csrf
                    <button type="submit"
                            class="tap-target-icon flex items-center justify-center"
                            aria-label="Cerrar sesión">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                        </svg>
                    </button>
                </form>
            </div>
        @endif
    </header>
    <main class="pb-safe">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
