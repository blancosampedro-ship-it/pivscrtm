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

    public int $step = 1;

    public string $estadoFinal = '';

    /** @var array<int, string>|string */
    public $recambios = [];

    public string $recambioOtro = '';

    public string $tiempoMinutos = '';

    public string $aspecto = 'OK';

    public string $funcionamiento = 'OK';

    public string $actuacion = 'OK';

    public string $audio = 'OK';

    public string $lineas = 'OK';

    public string $precisionPaso = 'OK';

    public string $precision_paso = 'OK';

    public string $diagnostico = '';

    public string $estado_final = '';

    public string $tiempo = '';

    public string $fecha = '';

    public string $ruta = '';

    public string $fecha_hora = '';

    public string $notas = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $fotos = [];

    public const RECAMBIOS_DISPONIBLES = [
        'Cable',
        'Conector',
        'Fuente alimentación',
        'Batería',
        'Pantalla',
        'Tarjeta SIM',
        'GPS',
        'Marquesina',
    ];

    public const TIEMPOS_DISPONIBLES = [
        ['value' => '5', 'label' => '5 min'],
        ['value' => '15', 'label' => '15 min'],
        ['value' => '30', 'label' => '30 min'],
        ['value' => '60', 'label' => '1 h'],
        ['value' => '90', 'label' => '1h 30'],
        ['value' => '120', 'label' => '2 h'],
    ];

    public function mount(Asignacion $asignacion): void
    {
        $tecnicoId = (int) auth()->user()->legacy_id;

        abort_unless((int) $asignacion->tecnico_id === $tecnicoId, 403);
        abort_unless((int) $asignacion->status === 1, 410);

        $this->asignacion = $asignacion->load(['averia.piv']);
    }

    public function next(): void
    {
        $this->validateCurrentStep();

        $max = (int) $this->asignacion->tipo === Asignacion::TIPO_CORRECTIVO ? 4 : 2;
        $this->step = min($this->step + 1, $max);
    }

    public function prev(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function setEstadoFinal(string $value): void
    {
        abort_unless(in_array($value, ['reparado', 'pendiente', 'no_reparable'], true), 422);

        $this->estadoFinal = $value;
        $this->step = 2;
    }

    public function toggleRecambio(string $name): void
    {
        if (! is_array($this->recambios)) {
            $this->recambios = [];
        }

        if (in_array($name, $this->recambios, true)) {
            $this->recambios = array_values(array_diff($this->recambios, [$name]));

            return;
        }

        $this->recambios[] = $name;
    }

    public function setTiempo(string $value): void
    {
        $this->tiempoMinutos = $value;
        $this->step = 4;
    }

    public function setRevisionItem(string $field, string $value): void
    {
        abort_unless(in_array($field, ['aspecto', 'funcionamiento', 'actuacion', 'audio', 'lineas', 'precisionPaso'], true), 422);
        abort_unless(in_array($value, ['OK', 'KO', 'N/A'], true), 422);

        $this->{$field} = $value;
    }

    private function validateCurrentStep(): void
    {
        $isCorrectivo = (int) $this->asignacion->tipo === Asignacion::TIPO_CORRECTIVO;

        if (! $isCorrectivo) {
            return;
        }

        match ($this->step) {
            1 => $this->validate(['estadoFinal' => ['required', 'in:reparado,pendiente,no_reparable']]),
            3 => $this->validate(['tiempoMinutos' => ['required', 'string']]),
            default => null,
        };
    }

    public function cerrar(AsignacionCierreService $service): void
    {
        $this->asignacion->refresh();

        $isCorrectivo = (int) $this->asignacion->tipo === Asignacion::TIPO_CORRECTIVO;

        if ($isCorrectivo && $this->asignacion->correctivo()->exists()) {
            $this->addError('cerrar', 'Esta asignación ya tiene un correctivo registrado.');

            return;
        }

        if (! $isCorrectivo && $this->asignacion->revision()->exists()) {
            $this->addError('cerrar', 'Esta asignación ya tiene una revisión registrada.');

            return;
        }

        $rules = [
            'fotos' => ['nullable', 'array', 'max:10'],
            'fotos.*' => ['image', 'max:8192'],
            'notas' => ['nullable', 'string', 'max:255'],
        ];

        if ($isCorrectivo) {
            if ($this->estado_final === '') {
                $rules['estadoFinal'] = ['required', 'in:reparado,pendiente,no_reparable'];
            }

            if ($this->tiempo === '') {
                $rules['tiempoMinutos'] = ['required', 'string'];
            }
        } else {
            $rules += [
                'aspecto' => ['required', 'in:OK,KO,N/A'],
                'funcionamiento' => ['required', 'in:OK,KO,N/A'],
                'actuacion' => ['required', 'in:OK,KO,N/A'],
                'audio' => ['required', 'in:OK,KO,N/A'],
                'lineas' => ['required', 'in:OK,KO,N/A'],
                'precisionPaso' => ['required', 'in:OK,KO,N/A'],
            ];
        }

        $this->validate($rules);

        $fotosPaths = [];
        foreach ($this->fotos as $foto) {
            $fotosPaths[] = $foto->store('piv-images/correctivo', 'public');
        }

        $data = $isCorrectivo
            ? [
                'diagnostico' => $this->buildDiagnostico(),
                'recambios' => $this->buildRecambios(),
                'estado_final' => $this->mapEstadoFinal(),
                'tiempo' => $this->mapTiempoToHoras(),
                'fotos' => $fotosPaths,
                'contrato' => false,
                'facturar_horas' => false,
                'facturar_desplazamiento' => false,
                'facturar_recambios' => false,
            ]
            : [
                'fecha' => $this->fecha !== '' ? $this->fecha : now()->format('Y-m-d'),
                'ruta' => $this->ruta ?: null,
                'fecha_hora' => $this->fecha_hora ?: null,
                'aspecto' => $this->aspecto,
                'funcionamiento' => $this->funcionamiento,
                'actuacion' => $this->actuacion,
                'audio' => $this->audio,
                'lineas' => $this->lineas,
                'precision_paso' => $this->precision_paso ?: $this->precisionPaso,
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

    public function buildDiagnostico(): string
    {
        if ($this->diagnostico !== '') {
            return $this->diagnostico;
        }

        $base = match ($this->estadoFinal) {
            'reparado' => 'Reparado',
            'pendiente' => 'Pendiente segunda visita',
            'no_reparable' => 'No reparable',
            default => '',
        };

        return $this->notas !== '' ? $base.'. '.$this->notas : $base;
    }

    public function buildRecambios(): string
    {
        if (is_string($this->recambios)) {
            return $this->recambios;
        }

        $items = $this->recambios;

        if ($this->recambioOtro !== '') {
            $items[] = $this->recambioOtro;
        }

        return $items === [] ? '—' : implode(', ', $items);
    }

    public function mapEstadoFinal(): string
    {
        if ($this->estado_final !== '') {
            return $this->estado_final;
        }

        return match ($this->estadoFinal) {
            'reparado' => 'OK',
            'pendiente' => 'Pendiente segunda visita',
            'no_reparable' => 'No reparable',
            default => 'OK',
        };
    }

    public function mapTiempoToHoras(): string
    {
        if ($this->tiempo !== '') {
            return $this->tiempo;
        }

        $mins = (int) $this->tiempoMinutos;
        if ($mins === 0) {
            return '';
        }

        $horas = $mins / 60;

        return rtrim(rtrim(number_format($horas, 2, '.', ''), '0'), '.');
    }
}; ?>

@php
    $isCorrectivo = (int) $asignacion->tipo === \App\Models\Asignacion::TIPO_CORRECTIVO;
    $totalSteps = $isCorrectivo ? 4 : 2;
    $piv = $asignacion->averia?->piv;
@endphp

<div class="min-h-screen bg-layer-0">
    <header class="bg-layer-0 border-b border-line-subtle px-4 py-3 flex items-center justify-between gap-3">
        <button wire:click="prev"
                type="button"
                class="min-h-14 px-3 text-md font-medium {{ $step === 1 ? 'opacity-30 pointer-events-none' : '' }}"
                aria-label="Atrás">
            ← Atrás
        </button>
        <div class="text-xs text-ink-secondary">Paso {{ $step }} de {{ $totalSteps }}</div>
        @if ($piv)
            <div class="text-xs text-ink-secondary font-mono truncate max-w-28">
                #{{ str_pad((string) $piv->piv_id, 3, '0', STR_PAD_LEFT) }}
            </div>
        @endif
    </header>

    <main class="p-4 pb-32">
        <div class="sr-only">Cerrar avería real Diagnóstico</div>
        @if ($piv?->parada_cod)
            <div class="sr-only">{{ $piv->parada_cod }}</div>
        @endif

        @if ($isCorrectivo)
            @include('livewire.tecnico.cierre-correctivo-steps')
        @else
            @include('livewire.tecnico.cierre-revision-steps')
        @endif

        @error('cerrar')
            <p class="text-error text-md font-medium mt-4 text-center">{{ $message }}</p>
        @enderror
    </main>
</div>