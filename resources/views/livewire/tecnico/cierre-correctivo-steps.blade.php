@php
    $estadoLabels = [
        'reparado' => 'Reparado',
        'pendiente' => 'Pendiente 2ª visita',
        'no_reparable' => 'No reparable',
    ];
    $recambiosSeleccionados = is_array($recambios) ? $recambios : [];
    $resumenEstado = $estadoLabels[$estadoFinal] ?? 'Sin elegir';
    $resumenRecambios = $this->buildRecambios();
    $resumenTiempo = $tiempoMinutos !== '' ? collect(self::TIEMPOS_DISPONIBLES)->firstWhere('value', $tiempoMinutos)['label'] ?? $tiempoMinutos.' min' : 'Sin elegir';
@endphp

@if ($step === 1)
    <section class="space-y-4">
        <div>
            <div class="text-xs uppercase tracking-wider text-error font-medium">Avería real</div>
            <h1 class="text-2xl font-semibold leading-tight mt-1">¿Qué pasó?</h1>
        </div>

        <button type="button" wire:click="setEstadoFinal('reparado')" class="w-full min-h-24 bg-success text-ink-on_color px-5 text-xl font-semibold text-left active:opacity-90">
            ✓ REPARADO
        </button>
        <button type="button" wire:click="setEstadoFinal('pendiente')" class="w-full min-h-24 bg-warning-soft text-ink-primary px-5 text-xl font-semibold text-left border border-warning active:bg-warning">
            ! PENDIENTE 2ª VISITA
        </button>
        <button type="button" wire:click="setEstadoFinal('no_reparable')" class="w-full min-h-24 bg-error text-ink-on_color px-5 text-xl font-semibold text-left active:opacity-90">
            × NO REPARABLE
        </button>
    </section>
@elseif ($step === 2)
    <section class="space-y-4">
        <div>
            <div class="text-xs uppercase tracking-wider text-ink-secondary font-medium">Paso rápido</div>
            <h1 class="text-2xl font-semibold leading-tight mt-1">¿Qué cambiaste?</h1>
        </div>

        <div class="grid grid-cols-1 gap-2">
            @foreach (self::RECAMBIOS_DISPONIBLES as $item)
                @php $selected = in_array($item, $recambiosSeleccionados, true); @endphp
                <button type="button" wire:click="toggleRecambio('{{ $item }}')" class="min-h-16 px-4 text-left text-lg font-medium border {{ $selected ? 'border-primary-60 bg-primary-10 text-ink-primary' : 'border-line-subtle bg-layer-0' }}">
                    <span class="font-mono mr-2">{{ $selected ? '✓' : '+' }}</span>{{ $item }}
                </button>
            @endforeach
        </div>

        <label class="block">
            <span class="block text-xs uppercase tracking-wider text-ink-secondary font-medium mb-2">Otro</span>
            <input type="text" wire:model="recambioOtro" class="block w-full border-0 border-b border-line-strong bg-layer-0 px-0 py-4 text-lg focus:border-primary-60 focus:ring-0" placeholder="Escribe otro recambio">
        </label>

        <div class="fixed inset-x-0 bottom-0 bg-layer-0 border-t border-line-subtle p-4 pb-safe">
            <button type="button" wire:click="next" class="w-full min-h-20 bg-primary-60 text-ink-on_color text-xl font-semibold">
                Siguiente →
            </button>
        </div>
    </section>
@elseif ($step === 3)
    <section class="space-y-4">
        <div>
            <div class="text-xs uppercase tracking-wider text-ink-secondary font-medium">Duración</div>
            <h1 class="text-2xl font-semibold leading-tight mt-1">¿Cuánto tiempo?</h1>
        </div>

        <div class="grid grid-cols-2 gap-3">
            @foreach (self::TIEMPOS_DISPONIBLES as $preset)
                <button type="button" wire:click="setTiempo('{{ $preset['value'] }}')" class="min-h-24 bg-layer-1 border border-line-subtle text-2xl font-semibold active:bg-primary-10">
                    {{ $preset['label'] }}
                </button>
            @endforeach
            <button type="button" wire:click="setTiempo('180')" class="col-span-2 min-h-20 bg-layer-0 border border-line-strong text-xl font-semibold">
                + más
            </button>
        </div>
    </section>
@else
    <section class="space-y-5">
        <div>
            <div class="text-xs uppercase tracking-wider text-ink-secondary font-medium">Confirmar cierre</div>
            <h1 class="text-2xl font-semibold leading-tight mt-1">Último vistazo</h1>
        </div>

        <div class="bg-layer-1 p-4 space-y-2 text-lg">
            <div><span class="text-ink-secondary">Estado:</span> <strong>{{ $resumenEstado }}</strong></div>
            <div><span class="text-ink-secondary">Recambios:</span> <strong>{{ $resumenRecambios }}</strong></div>
            <div><span class="text-ink-secondary">Tiempo:</span> <strong>{{ $resumenTiempo }}</strong></div>
        </div>

        <div>
            <label for="fotos" class="block text-xs uppercase tracking-wider text-ink-secondary font-medium mb-2">Foto del panel</label>
            <input id="fotos" type="file" wire:model="fotos" accept="image/*" capture="environment" multiple class="block w-full text-md text-ink-secondary file:mr-4 file:border-0 file:bg-primary-60 file:px-5 file:py-4 file:text-md file:font-semibold file:text-ink-on_color">
            @error('fotos.*') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            @if (count($fotos) > 0)
                <div class="grid grid-cols-3 gap-2 mt-3">
                    @foreach ($fotos as $foto)
                        <img src="{{ $foto->temporaryUrl() }}" alt="Previsualización foto cierre" class="w-full h-24 object-cover bg-layer-1">
                    @endforeach
                </div>
            @endif
        </div>

        <div>
            <label for="notas" class="block text-xs uppercase tracking-wider text-ink-secondary font-medium mb-2">Nota opcional</label>
            <textarea id="notas" wire:model="notas" rows="3" class="block w-full border-0 border-b border-line-strong bg-layer-0 px-0 py-4 text-lg focus:border-primary-60 focus:ring-0" placeholder="Añade una nota si hace falta"></textarea>
        </div>

        @include('livewire.tecnico.partials.voice-dictation')

        <div class="fixed inset-x-0 bottom-0 bg-layer-0 border-t border-line-subtle p-4 pb-safe">
            <button type="button" wire:click="cerrar" class="w-full min-h-24 bg-success text-ink-on_color text-xl font-semibold">
                ✓ CERRAR ASIGNACIÓN
            </button>
        </div>
    </section>
@endif