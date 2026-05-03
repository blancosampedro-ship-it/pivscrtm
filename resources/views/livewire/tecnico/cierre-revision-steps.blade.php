@php
    $items = [
        'aspecto' => 'Aspecto',
        'funcionamiento' => 'Funcionamiento',
        'actuacion' => 'Actuación',
        'audio' => 'Audio',
        'lineas' => 'Líneas',
        'precisionPaso' => 'Precisión paso',
    ];
@endphp

@if ($step === 1)
    <section class="space-y-4">
        <div>
            <div class="text-xs uppercase tracking-wider text-success font-medium">Revisión mensual</div>
            <h1 class="text-2xl font-semibold leading-tight mt-1">Checklist rápido</h1>
        </div>

        <div class="space-y-3">
            @foreach ($items as $field => $label)
                <div class="bg-layer-1 p-3">
                    <div class="text-lg font-medium mb-2">{{ $label }}</div>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (['OK', 'KO', 'N/A'] as $value)
                            @php $selected = $$field === $value; @endphp
                            <button type="button" wire:click="setRevisionItem('{{ $field }}', '{{ $value }}')" class="min-h-16 text-lg font-semibold border {{ $selected ? 'bg-primary-60 text-ink-on_color border-primary-60' : 'bg-layer-0 border-line-subtle' }}">
                                {{ $value }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="fixed inset-x-0 bottom-0 bg-layer-0 border-t border-line-subtle p-4 pb-safe">
            <button type="button" wire:click="next" class="w-full min-h-20 bg-primary-60 text-ink-on_color text-xl font-semibold">
                Siguiente →
            </button>
        </div>
    </section>
@else
    <section class="space-y-5">
        <div>
            <div class="text-xs uppercase tracking-wider text-ink-secondary font-medium">Confirmar revisión</div>
            <h1 class="text-2xl font-semibold leading-tight mt-1">Resumen</h1>
        </div>

        <div class="bg-layer-1 p-4 space-y-2">
            @foreach ($items as $field => $label)
                @php $value = $$field; @endphp
                <div class="flex justify-between gap-3 text-lg {{ $value !== 'OK' ? 'text-error font-semibold' : '' }}">
                    <span>{{ $label }}</span>
                    <span>{{ $value }}</span>
                </div>
            @endforeach
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