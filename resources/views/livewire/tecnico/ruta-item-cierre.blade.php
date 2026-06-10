<?php

use App\Models\LvRutaDiaItem;
use App\Models\Tecnico;
use App\Services\RutaDiaItemCierreService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\layout;
use function Livewire\Volt\title;

layout('components.tecnico.shell');
title('Winfin PIV - Cierre ruta');

new class extends Component {
    use WithFileUploads;

    public LvRutaDiaItem $item;

    public int $step = 1;

    public string $status = LvRutaDiaItem::STATUS_CERRADO;

    public string $causa_no_resolucion = '';

    public string $causaCategoria = '';

    public string $notas = '';

    public string $aspecto = 'OK';

    public string $audio = 'OK';

    public string $lineas = 'OK';

    public string $fecha_hora = 'OK';

    public string $ruta = 'OK';

    public string $precisionPaso = 'OK';

    public string $precision_paso = 'OK';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $fotos = [];

    public const CAUSAS = [
        'Sin tensión',
        'Software Indra',
        'Pieza no disponible',
        'Acceso bloqueado',
        'Otro',
    ];

    public function mount(int $itemId): void
    {
        $item = LvRutaDiaItem::query()
            ->with([
                'rutaDia',
                'averiaIcca.piv.municipioModulo',
                'revisionPendiente.piv.municipioModulo',
                'revisionPendiente.carryOverOrigen',
            ])
            ->findOrFail($itemId);

        abort_unless((int) $item->rutaDia->tecnico_id === (int) auth()->user()->legacy_id, 403);

        if (in_array($item->status, [LvRutaDiaItem::STATUS_CERRADO, LvRutaDiaItem::STATUS_NO_RESUELTO], true)) {
            session()->flash('cierre_error', 'Este item ya fue cerrado.');
            $this->redirect(route('tecnico.dashboard'), navigate: false);

            return;
        }

        $this->item = $item;
    }

    public function setStatus(string $status): void
    {
        abort_unless(in_array($status, [LvRutaDiaItem::STATUS_CERRADO, LvRutaDiaItem::STATUS_NO_RESUELTO], true), 422);

        $this->status = $status;
    }

    public function setChecklistItem(string $field, string $value): void
    {
        abort_unless(in_array($field, ['aspecto', 'audio', 'lineas', 'fecha_hora', 'ruta', 'precisionPaso'], true), 422);
        abort_unless(in_array($value, ['OK', 'KO', 'N/A'], true), 422);

        $this->{$field} = $value;
        if ($field === 'precisionPaso') {
            $this->precision_paso = $value;
        }
    }

    public function next(): void
    {
        $this->validateCurrentStep();
        $this->step = min($this->step + 1, $this->totalSteps());
    }

    public function prev(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function cerrar(RutaDiaItemCierreService $service): void
    {
        $rules = [
            'status' => ['required', 'in:'.LvRutaDiaItem::STATUS_CERRADO.','.LvRutaDiaItem::STATUS_NO_RESUELTO],
            'notas' => ['nullable', 'string', 'max:500'],
            'fotos' => ['nullable', 'array', 'max:10'],
            'fotos.*' => ['image', 'max:8192'],
        ];

        if ($this->status === LvRutaDiaItem::STATUS_NO_RESUELTO) {
            $rules['causaCategoria'] = ['required', 'in:'.implode(',', self::CAUSAS)];
            $rules['causa_no_resolucion'] = ['required', 'string', 'max:500'];
        }

        if (! $this->isCorrectivo()) {
            $rules += [
                'aspecto' => ['required', 'in:OK,KO,N/A'],
                'audio' => ['required', 'in:OK,KO,N/A'],
                'lineas' => ['required', 'in:OK,KO,N/A'],
                'fecha_hora' => ['required', 'in:OK,KO,N/A'],
                'ruta' => ['required', 'in:OK,KO,N/A'],
                'precisionPaso' => ['required', 'in:OK,KO,N/A'],
            ];
        }

        $this->validate($rules);

        $fotosPaths = [];
        foreach ($this->fotos as $foto) {
            $fotosPaths[] = $foto->store('piv-images/ruta-dia-item/'.$this->item->id, 'public');
        }

        $causa = trim($this->causaCategoria.($this->causa_no_resolucion !== '' ? ': '.$this->causa_no_resolucion : ''));

        try {
            $service->cerrar($this->item, [
                'status' => $this->status,
                'causa_no_resolucion' => $causa,
                'notas_tecnico' => $this->notas,
                'aspecto' => $this->aspecto,
                'funcionamiento' => $this->aspecto,
                'actuacion' => $this->aspecto,
                'audio' => $this->audio,
                'lineas' => $this->lineas,
                'fecha_hora' => $this->fecha_hora,
                'ruta' => $this->ruta,
                'precision_paso' => $this->precision_paso ?: $this->precisionPaso,
                'fotos' => $fotosPaths,
            ], Tecnico::findOrFail((int) auth()->user()->legacy_id));
        } catch (ValidationException $exception) {
            $this->addError('cerrar', collect($exception->errors())->flatten()->first() ?? 'No se pudo cerrar.');

            return;
        } catch (\DomainException $exception) {
            $this->addError('cerrar', $exception->getMessage());

            return;
        }

        session()->flash('cierre_ok', 'Item cerrado: '.$this->panelLabel());
        $this->redirect(route('tecnico.dashboard'), navigate: false);
    }

    private function validateCurrentStep(): void
    {
        if ($this->isCorrectivo() && $this->step === 1) {
            $this->validate(['status' => ['required', 'in:'.LvRutaDiaItem::STATUS_CERRADO.','.LvRutaDiaItem::STATUS_NO_RESUELTO]]);
        }

        if ($this->isCorrectivo() && $this->step === 2 && $this->status === LvRutaDiaItem::STATUS_NO_RESUELTO) {
            $this->validate([
                'causaCategoria' => ['required', 'in:'.implode(',', self::CAUSAS)],
                'causa_no_resolucion' => ['required', 'string', 'max:500'],
            ]);
        }
    }

    public function totalSteps(): int
    {
        return $this->isCorrectivo() ? 3 : 2;
    }

    public function isCorrectivo(): bool
    {
        return $this->item->tipo_item === LvRutaDiaItem::TIPO_CORRECTIVO;
    }

    public function panelLabel(): string
    {
        $piv = $this->isCorrectivo() ? $this->item->averiaIcca?->piv : $this->item->revisionPendiente?->piv;

        return $piv?->parada_cod ?? $this->item->averiaIcca?->panel_id_sgip ?? '#'.$this->item->id;
    }
}; ?>

@php
    $isCorrectivo = $this->isCorrectivo();
    $piv = $isCorrectivo ? $item->averiaIcca?->piv : $item->revisionPendiente?->piv;
    $checkItems = [
        'aspecto' => 'Aspecto',
        'audio' => 'Audio',
        'lineas' => 'Líneas',
        'fecha_hora' => 'Fecha',
        'ruta' => 'Ruta',
        'precisionPaso' => 'Precisión paso',
    ];
@endphp

<div class="min-h-screen bg-layer-0">
    <header class="bg-layer-0 border-b border-line-subtle px-4 py-3 flex items-center justify-between gap-3">
        <button wire:click="prev" type="button" class="min-h-14 px-3 text-md font-medium {{ $step === 1 ? 'opacity-30 pointer-events-none' : '' }}" aria-label="Atrás">← Atrás</button>
        <div class="text-xs text-ink-secondary">Paso {{ $step }} de {{ $this->totalSteps() }}</div>
        <div class="text-xs text-ink-secondary font-mono truncate max-w-28">{{ $this->panelLabel() }}</div>
    </header>

    <main class="p-4 pb-32">
        <div class="mb-5">
            <div class="text-xs uppercase tracking-wider {{ $isCorrectivo ? 'text-error' : 'text-primary-60' }} font-medium">
                {{ $isCorrectivo ? 'Correctivo ICCA' : ($item->tipo_item === \App\Models\LvRutaDiaItem::TIPO_CARRY_OVER ? 'Carry over' : 'Preventivo') }}
            </div>
            <h1 class="text-2xl font-semibold leading-tight mt-1">{{ $piv?->parada_cod ?? $item->averiaIcca?->panel_id_sgip ?? 'Panel sin resolver' }}</h1>
            <div class="text-sm text-ink-secondary mt-1">{{ $piv?->municipioModulo?->nombre ?? 'Municipio sin resolver' }}</div>
            @if (! $isCorrectivo && $item->revisionPendiente?->carryOverOrigen?->decision_notas)
                <div class="mt-3 bg-layer-1 border-l-4 border-warning p-3 text-sm text-ink-secondary">
                    {{ $item->revisionPendiente->carryOverOrigen->decision_notas }}
                </div>
            @endif
        </div>

        @if ($isCorrectivo)
            @if ($step === 1)
                <section class="space-y-4">
                    <h2 class="text-xl font-semibold">Estado final</h2>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" wire:click="setStatus('{{ \App\Models\LvRutaDiaItem::STATUS_CERRADO }}')" class="min-h-20 text-lg font-semibold border {{ $status === \App\Models\LvRutaDiaItem::STATUS_CERRADO ? 'bg-success text-ink-on_color border-success' : 'bg-layer-0 border-line-subtle' }}">Resuelto</button>
                        <button type="button" wire:click="setStatus('{{ \App\Models\LvRutaDiaItem::STATUS_NO_RESUELTO }}')" class="min-h-20 text-lg font-semibold border {{ $status === \App\Models\LvRutaDiaItem::STATUS_NO_RESUELTO ? 'bg-error text-ink-on_color border-error' : 'bg-layer-0 border-line-subtle' }}">No resuelto</button>
                    </div>
                </section>
            @elseif ($step === 2)
                <section class="space-y-5">
                    @if ($status === \App\Models\LvRutaDiaItem::STATUS_NO_RESUELTO)
                        <div>
                            <label for="causaCategoria" class="block text-xs uppercase tracking-wider text-ink-secondary font-medium mb-2">Causa</label>
                            <select id="causaCategoria" wire:model="causaCategoria" class="block w-full border border-line-strong bg-layer-0 p-4 text-lg">
                                <option value="">Selecciona causa</option>
                                @foreach (self::CAUSAS as $causa)
                                    <option value="{{ $causa }}">{{ $causa }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="causa_no_resolucion" class="block text-xs uppercase tracking-wider text-ink-secondary font-medium mb-2">Detalle</label>
                            <textarea id="causa_no_resolucion" wire:model="causa_no_resolucion" rows="3" class="block w-full border-0 border-b border-line-strong bg-layer-0 px-0 py-4 text-lg focus:border-primary-60 focus:ring-0"></textarea>
                        </div>
                    @endif
                    <div>
                        <label for="notas" class="block text-xs uppercase tracking-wider text-ink-secondary font-medium mb-2">Nota técnica</label>
                        <textarea id="notas" wire:model="notas" rows="4" class="block w-full border-0 border-b border-line-strong bg-layer-0 px-0 py-4 text-lg focus:border-primary-60 focus:ring-0" placeholder="Qué has hecho o qué queda pendiente"></textarea>
                    </div>
                    @include('livewire.tecnico.partials.voice-dictation')
                </section>
            @else
                <section class="space-y-5">
                    <h2 class="text-xl font-semibold">Confirmar cierre</h2>
                    <input type="file" wire:model="fotos" multiple accept="image/*" class="block w-full text-md">
                </section>
            @endif
        @else
            @if ($step === 1)
                <section class="space-y-3">
                    @foreach ($checkItems as $field => $label)
                        <div class="bg-layer-1 p-3">
                            <div class="text-lg font-medium mb-2">{{ $label }}</div>
                            <div class="grid grid-cols-3 gap-2">
                                @foreach (['OK', 'KO', 'N/A'] as $value)
                                    @php $selected = $$field === $value; @endphp
                                    <button type="button" wire:click="setChecklistItem('{{ $field }}', '{{ $value }}')" class="min-h-16 text-lg font-semibold border {{ $selected ? 'bg-primary-60 text-ink-on_color border-primary-60' : 'bg-layer-0 border-line-subtle' }}">{{ $value }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </section>
            @else
                <section class="space-y-5">
                    <div class="bg-layer-1 p-4 space-y-2">
                        @foreach ($checkItems as $field => $label)
                            @php $value = $$field; @endphp
                            <div class="flex justify-between gap-3 text-lg {{ $value !== 'OK' ? 'text-error font-semibold' : '' }}">
                                <span>{{ $label }}</span>
                                <span>{{ $value }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div>
                        <label for="notas" class="block text-xs uppercase tracking-wider text-ink-secondary font-medium mb-2">Nota opcional</label>
                        <textarea id="notas" wire:model="notas" rows="3" class="block w-full border-0 border-b border-line-strong bg-layer-0 px-0 py-4 text-lg focus:border-primary-60 focus:ring-0"></textarea>
                    </div>
                    @include('livewire.tecnico.partials.voice-dictation')
                    <input type="file" wire:model="fotos" multiple accept="image/*" class="block w-full text-md">
                </section>
            @endif
        @endif

        @error('cerrar')
            <p class="text-error text-md font-medium mt-4 text-center">{{ $message }}</p>
        @enderror
    </main>

    <div class="fixed inset-x-0 bottom-0 bg-layer-0 border-t border-line-subtle p-4 pb-safe">
        @if ($step < $this->totalSteps())
            <button type="button" wire:click="next" class="w-full min-h-20 bg-primary-60 text-ink-on_color text-xl font-semibold">Siguiente →</button>
        @else
            <button type="button" wire:click="cerrar" class="w-full min-h-24 bg-success text-ink-on_color text-xl font-semibold">✓ CERRAR ITEM</button>
        @endif
    </div>
</div>