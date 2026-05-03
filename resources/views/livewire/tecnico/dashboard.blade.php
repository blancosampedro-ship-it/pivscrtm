<?php

use App\Models\Asignacion;
use App\Models\Tecnico;
use Livewire\Volt\Component;
use function Livewire\Volt\layout;
use function Livewire\Volt\title;

layout('components.tecnico.shell');
title('Winfin PIV - Mis cierres');

new class extends Component {
    public int $previousCount = 0;

    public bool $hasNewSinceLastPoll = false;

    public function with(): array
    {
        $tecnicoId = (int) auth()->user()->legacy_id;

        $asignacionesAbiertas = Asignacion::query()
            ->where('tecnico_id', $tecnicoId)
            ->where('status', 1)
            ->with(['averia.piv.imagenes'])
            ->orderByDesc('fecha')
            ->get();

        $count = $asignacionesAbiertas->count();
        $this->hasNewSinceLastPoll = $this->previousCount > 0 && $count > $this->previousCount;
        $this->previousCount = $count;

        $tecnico = Tecnico::find($tecnicoId);

        return [
            'asignacionesAbiertas' => $asignacionesAbiertas,
            'tecnicoNombre' => $tecnico?->nombre_completo ?? '—',
            'hasNew' => $this->hasNewSinceLastPoll,
        ];
    }
}; ?>

<div class="min-h-screen bg-layer-0" wire:poll.30s>
    <header class="bg-layer-0 border-b border-line-subtle px-4 py-3 flex items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="text-xs uppercase tracking-wider text-ink-secondary">Hola</div>
            <div class="text-lg font-medium leading-tight truncate">{{ $tecnicoNombre }}</div>
        </div>
        <form action="{{ route('tecnico.logout') }}" method="POST" class="shrink-0">
            @csrf
            <button type="submit"
                    class="min-h-16 px-5 text-md font-medium border border-line-strong bg-layer-0 hover:bg-layer-1 active:bg-layer-2"
                    aria-label="Salir">
                ⏏ Salir
            </button>
        </form>
    </header>

    @if (session('cierre_ok'))
        <div class="bg-success-soft text-ink-primary border-l-4 border-success p-4 mx-4 mt-4 text-md font-medium" role="status">
            ✓ {{ session('cierre_ok') }}
        </div>
    @endif

    <main class="p-4">
        <div class="sr-only">Mis asignaciones abiertas</div>

        @if ($asignacionesAbiertas->isEmpty())
            <div class="bg-layer-1 p-12 text-center">
                <div class="text-4xl mb-3">☕</div>
                <div class="text-md text-ink-secondary">No hay nada pendiente.</div>
                <div class="text-sm text-ink-secondary mt-1">Cuando te asignen una avería aparecerá aquí.</div>
            </div>
        @else
            <h1 class="text-xs uppercase tracking-wider text-ink-secondary font-medium mb-3">
                {{ $asignacionesAbiertas->count() }} {{ $asignacionesAbiertas->count() === 1 ? 'asignación abierta' : 'asignaciones abiertas' }}
            </h1>

            <ul class="space-y-3" role="list">
                @foreach ($asignacionesAbiertas as $asignacion)
                    @php
                        $isCorrectivo = (int) $asignacion->tipo === \App\Models\Asignacion::TIPO_CORRECTIVO;
                        $piv = $asignacion->averia?->piv;
                        $photoUrl = $piv?->current_photo_url;
                        $kicker = $isCorrectivo ? '⚠ AVERÍA REAL' : '✓ REVISIÓN MENSUAL';
                        $legacyKicker = $isCorrectivo ? 'Avería real' : 'Revisión mensual';
                        $kickerColor = $isCorrectivo ? 'text-error' : 'text-success';
                        $isNew = $hasNew && $loop->first;
                    @endphp
                    <li data-asignacion-card>
                        <a href="{{ route('tecnico.asignacion.cierre', $asignacion) }}"
                           class="block bg-layer-0 border border-line-subtle hover:border-line-strong active:bg-layer-1 transition-colors {{ $isNew ? 'animate-pulse-slow ring-2 ring-primary-60' : '' }}">
                            <div class="flex min-h-24">
                                <div class="w-24 h-24 flex-shrink-0 bg-layer-1 overflow-hidden">
                                    @if ($photoUrl)
                                        <img src="{{ $photoUrl }}"
                                             alt="Panel {{ $piv?->piv_id }}"
                                             class="w-full h-full object-cover"
                                             loading="lazy"
                                             onerror="this.src='/img/panel-placeholder.svg'; this.classList.add('opacity-40');">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center text-3xl text-ink-secondary opacity-40">📷</div>
                                    @endif
                                </div>

                                <div class="flex-1 p-3 flex flex-col justify-center min-w-0">
                                    <div class="text-xs uppercase tracking-wider {{ $kickerColor }} font-medium">
                                        {{ $kicker }}
                                        <span class="sr-only">{{ $legacyKicker }}</span>
                                    </div>
                                    @if ($piv)
                                        <div class="text-md font-medium leading-tight truncate">
                                            Panel #{{ str_pad((string) $piv->piv_id, 3, '0', STR_PAD_LEFT) }}
                                            <span class="font-mono text-sm text-ink-secondary ml-1">· {{ $piv->parada_cod }}</span>
                                        </div>
                                        <div class="text-sm text-ink-secondary leading-snug truncate">
                                            {{ $piv->direccion }}
                                        </div>
                                    @else
                                        <div class="text-md font-medium text-ink-secondary">Sin panel asignado</div>
                                    @endif
                                </div>

                                <div class="flex items-center justify-center px-3 text-ink-secondary text-2xl" aria-hidden="true">→</div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </main>
</div>