<?php

use App\Models\Asignacion;
use App\Services\AsignacionCierreService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\layout;
use function Livewire\Volt\title;

layout('components.tecnico.shell');
title('Winfin PIV - Cierre');

new class extends Component {
    use WithFileUploads;

    public Asignacion $asignacion;

    public string $diagnostico = '';

    public string $recambios = '';

    public string $estado_final = 'OK';

    public string $tiempo = '';

    public string $fecha = '';

    public string $ruta = '';

    public string $fecha_hora = '';

    public string $aspecto = 'OK';

    public string $funcionamiento = 'OK';

    public string $actuacion = 'OK';

    public string $audio = 'OK';

    public string $lineas = 'OK';

    public string $precision_paso = 'OK';

    public string $notas = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $fotos = [];

    public function mount(Asignacion $asignacion): void
    {
        $tecnicoId = (int) auth()->user()->legacy_id;

        abort_unless((int) $asignacion->tecnico_id === $tecnicoId, 403);
        abort_unless((int) $asignacion->status === 1, 410);

        $this->asignacion = $asignacion->load(['averia.piv']);

        if ((int) $asignacion->tipo === Asignacion::TIPO_REVISION) {
            $this->fecha = now()->format('Y-m-d');
        }
    }

    public function cerrar(AsignacionCierreService $service): void
    {
        $isCorrectivo = (int) $this->asignacion->tipo === Asignacion::TIPO_CORRECTIVO;

        $rules = $isCorrectivo
            ? [
                'diagnostico' => ['required', 'string', 'max:255'],
                'recambios' => ['required', 'string', 'max:255'],
                'estado_final' => ['required', 'string', 'max:100'],
                'tiempo' => ['nullable', 'string', 'max:45'],
                'fotos' => ['nullable', 'array', 'max:10'],
                'fotos.*' => ['image', 'max:8192'],
            ]
            : [
                'fecha' => ['nullable', 'date'],
                'ruta' => ['nullable', 'string', 'max:100'],
                'fecha_hora' => ['nullable', 'string', 'max:100'],
                'aspecto' => ['required', 'in:OK,KO,N/A'],
                'funcionamiento' => ['required', 'in:OK,KO,N/A'],
                'actuacion' => ['required', 'in:OK,KO,N/A'],
                'audio' => ['required', 'in:OK,KO,N/A'],
                'lineas' => ['required', 'in:OK,KO,N/A'],
                'precision_paso' => ['required', 'in:OK,KO,N/A'],
                'notas' => ['nullable', 'string', 'max:100'],
            ];

        $this->validate($rules);

        $fotosPaths = [];
        foreach ($this->fotos as $foto) {
            $fotosPaths[] = $foto->store('piv-images/correctivo', 'public');
        }

        $data = $isCorrectivo
            ? [
                'diagnostico' => $this->diagnostico,
                'recambios' => $this->recambios,
                'estado_final' => $this->estado_final,
                'tiempo' => $this->tiempo ?: null,
                'fotos' => $fotosPaths,
                'contrato' => false,
                'facturar_horas' => false,
                'facturar_desplazamiento' => false,
                'facturar_recambios' => false,
            ]
            : [
                'fecha' => $this->fecha ?: null,
                'ruta' => $this->ruta ?: null,
                'fecha_hora' => $this->fecha_hora ?: null,
                'aspecto' => $this->aspecto,
                'funcionamiento' => $this->funcionamiento,
                'actuacion' => $this->actuacion,
                'audio' => $this->audio,
                'lineas' => $this->lineas,
                'precision_paso' => $this->precision_paso,
                'notas' => $this->notas ?: null,
            ];

        try {
            $service->cerrar($this->asignacion, $data);
        } catch (ValidationException $exception) {
            $this->addError('cerrar', collect($exception->errors())->flatten()->first() ?? 'No se pudo cerrar.');

            return;
        }

        session()->flash('cierre_ok', 'Asignación #'.$this->asignacion->asignacion_id.' cerrada.');

        $this->redirect(route('tecnico.dashboard'), navigate: false);
    }
}; ?>

@php
    $isCorrectivo = (int) $asignacion->tipo === \App\Models\Asignacion::TIPO_CORRECTIVO;
    $stripe = $isCorrectivo ? 'border-error' : 'border-success';
    $kicker = $isCorrectivo ? 'Cerrar avería real' : 'Cerrar revisión mensual';
    $piv = $asignacion->averia?->piv;
    $fieldClass = 'block w-full border-0 border-b border-line-strong bg-layer-0 px-0 py-3 text-md text-ink-primary placeholder:text-ink-placeholder focus:border-primary-60 focus:ring-0';
    $labelClass = 'block text-xs uppercase tracking-wider text-ink-secondary font-medium mb-2';
@endphp

<div class="max-w-md mx-auto p-4 pb-32">
    <div class="border-l-4 {{ $stripe }} bg-layer-0 p-4 mb-5">
        <div class="text-xs uppercase tracking-wider text-ink-secondary font-medium mb-1">{{ $kicker }}</div>
        @if ($piv)
            <div class="text-md font-medium leading-tight">
                Panel #{{ str_pad((string) $piv->piv_id, 3, '0', STR_PAD_LEFT) }}
                <span class="font-mono text-sm text-ink-secondary ml-1">· {{ $piv->parada_cod }}</span>
            </div>
            <div class="text-sm text-ink-secondary leading-snug mt-1">{{ $piv->direccion }}</div>
        @else
            <div class="text-md font-medium leading-tight text-ink-secondary">Panel sin asignar</div>
        @endif
    </div>

    <form wire:submit="cerrar" class="space-y-6" enctype="multipart/form-data">
        @if ($isCorrectivo)
            <div>
                <label for="diagnostico" class="{{ $labelClass }}">Diagnóstico</label>
                <textarea id="diagnostico" wire:model="diagnostico" rows="3" class="{{ $fieldClass }} resize-none" placeholder="Qué se diagnosticó como problema"></textarea>
                @error('diagnostico') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="recambios" class="{{ $labelClass }}">Acción / Recambio</label>
                <textarea id="recambios" wire:model="recambios" rows="3" class="{{ $fieldClass }} resize-none" placeholder="Qué se cambió o reparó"></textarea>
                @error('recambios') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="estado_final" class="{{ $labelClass }}">Estado final</label>
                <input id="estado_final" type="text" wire:model="estado_final" class="{{ $fieldClass }}">
                @error('estado_final') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="tiempo" class="{{ $labelClass }}">Tiempo (horas)</label>
                <input id="tiempo" type="text" inputmode="decimal" wire:model="tiempo" class="{{ $fieldClass }}" placeholder="1.5">
                @error('tiempo') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="fotos" class="{{ $labelClass }}">Fotos del cierre</label>
                <input id="fotos" type="file" wire:model="fotos" accept="image/*" capture="environment" multiple class="block w-full text-sm text-ink-secondary file:mr-4 file:border-0 file:bg-layer-1 file:px-4 file:py-3 file:text-sm file:font-medium file:text-ink-primary">
                @error('fotos') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                @error('fotos.*') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror

                @if (count($fotos) > 0)
                    <div class="grid grid-cols-3 gap-2 mt-3">
                        @foreach ($fotos as $foto)
                            <img src="{{ $foto->temporaryUrl() }}" alt="Previsualización foto cierre" class="w-full h-24 object-cover bg-layer-1">
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            <div>
                <label for="fecha" class="{{ $labelClass }}">Fecha</label>
                <input id="fecha" type="date" wire:model="fecha" class="{{ $fieldClass }}">
                @error('fecha') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="ruta" class="{{ $labelClass }}">Ruta</label>
                <input id="ruta" type="text" wire:model="ruta" class="{{ $fieldClass }}">
                @error('ruta') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="fecha_hora" class="{{ $labelClass }}">Verificación fecha/hora</label>
                <input id="fecha_hora" type="text" wire:model="fecha_hora" class="{{ $fieldClass }}">
                @error('fecha_hora') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 gap-4">
                @foreach ([
                    'aspecto' => 'Aspecto',
                    'funcionamiento' => 'Funcionamiento',
                    'actuacion' => 'Actuación',
                    'audio' => 'Audio',
                    'lineas' => 'Líneas',
                    'precision_paso' => 'Precisión paso',
                ] as $field => $label)
                    <div>
                        <label for="{{ $field }}" class="{{ $labelClass }}">{{ $label }}</label>
                        <select id="{{ $field }}" wire:model="{{ $field }}" class="{{ $fieldClass }}">
                            <option value="OK">OK</option>
                            <option value="KO">KO</option>
                            <option value="N/A">N/A</option>
                        </select>
                        @error($field) <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>

            <div>
                <label for="notas" class="{{ $labelClass }}">Notas</label>
                <textarea id="notas" wire:model="notas" rows="3" maxlength="100" class="{{ $fieldClass }} resize-none" placeholder="Opcional"></textarea>
                @error('notas') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror
            </div>
        @endif

        @error('cerrar') <p class="text-error text-sm">{{ $message }}</p> @enderror

        <div class="fixed inset-x-0 bottom-0 bg-layer-0 border-t border-line-subtle p-4 pb-safe">
            <button type="submit" class="tap-target w-full min-h-14 bg-primary-60 hover:bg-primary-70 text-ink-on_color font-medium text-md transition-colors duration-fast-01 ease-carbon-productive">
                <span wire:loading.remove wire:target="cerrar">Registrar cierre</span>
                <span wire:loading wire:target="cerrar">Guardando...</span>
            </button>
        </div>
    </form>
</div>