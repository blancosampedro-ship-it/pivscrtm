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
                <li class="bg-layer-0 border-l-4 {{ $stripeColor }} p-4 flex flex-col gap-2 shadow-none" data-asignacion-card>
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
                </li>
            @endforeach
        </ul>
    @endif
</div>
