<?php

use App\Models\Asignacion;
use Livewire\Volt\Component;
use function Livewire\Volt\layout;
use function Livewire\Volt\title;

layout('components.tecnico.shell');
title('Winfin PIV - Técnico');

new class extends Component {
    public function with(): array
    {
        $tecnicoId = (int) auth()->user()->legacy_id;

        return [
            'asignacionesAbiertas' => Asignacion::query()
                ->where('tecnico_id', $tecnicoId)
                ->where('status', 1)
                ->with(['averia.piv'])
                ->orderByDesc('fecha')
                ->get(),
        ];
    }
}; ?>

<div class="p-4">
    @if (session('cierre_ok'))
        <div class="bg-success-soft text-ink-primary border-l-4 border-success p-3 mb-4 text-sm" role="status">
            {{ session('cierre_ok') }}
        </div>
    @endif

    <h1 class="text-lg font-medium mb-4">Mis asignaciones abiertas</h1>

    @if ($asignacionesAbiertas->isEmpty())
        <div class="bg-layer-1 p-8 text-center text-ink-secondary text-sm">
            No tienes asignaciones abiertas ahora mismo.
        </div>
    @else
        <ul class="space-y-3" role="list">
            @foreach ($asignacionesAbiertas as $asignacion)
                @php
                    $isCorrectivo = (int) $asignacion->tipo === \App\Models\Asignacion::TIPO_CORRECTIVO;
                    $stripeColor = $isCorrectivo ? 'border-error' : 'border-success';
                    $kicker = $isCorrectivo ? 'Avería real' : 'Revisión mensual';
                    $subtitle = $isCorrectivo
                        ? 'Hay un fallo. Crear parte correctivo.'
                        : 'Todo OK. Checklist mensual rutinario.';
                    $piv = $asignacion->averia?->piv;
                @endphp
                <li data-asignacion-card>
                    <a href="{{ route('tecnico.asignacion.cierre', $asignacion) }}" class="bg-layer-0 border-l-4 {{ $stripeColor }} p-4 flex gap-3 shadow-none min-h-24 hover:bg-layer-hover focus:bg-layer-hover focus:outline-none focus:ring-2 focus:ring-primary-60">
                        <div class="flex flex-1 flex-col gap-2">
                            <div class="text-xs uppercase tracking-wider text-ink-secondary font-medium">
                                {{ $kicker }}
                            </div>
                            <div class="text-md font-medium leading-tight">
                                @if ($piv)
                                    Panel #{{ str_pad((string) $piv->piv_id, 3, '0', STR_PAD_LEFT) }}
                                    <span class="font-mono text-sm text-ink-secondary ml-1">· {{ $piv->parada_cod }}</span>
                                @else
                                    <span class="text-ink-secondary">Panel sin asignar</span>
                                @endif
                            </div>
                            @if ($piv)
                                <div class="text-sm text-ink-secondary leading-snug">
                                    {{ $piv->direccion }}
                                </div>
                            @endif
                            <div class="text-xs text-ink-secondary mt-1">
                                {{ $subtitle }}
                            </div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 shrink-0 self-center text-ink-secondary" aria-hidden="true">
                            <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
