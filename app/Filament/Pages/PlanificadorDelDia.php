<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\PlanificadorDelDiaService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

final class PlanificadorDelDia extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Planificación';

    protected static ?string $navigationLabel = 'Planificador del día';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $slug = 'planificador-dia';

    protected static string $view = 'filament.pages.planificador-del-dia';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $resultado = null;

    public function mount(): void
    {
        $this->form->fill([
            'fecha' => CarbonImmutable::now('Europe/Madrid')->format('Y-m-d'),
        ]);

        $this->recalcular();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('fecha')
                    ->label('Fecha')
                    ->required()
                    ->displayFormat('Y-m-d')
                    ->live()
                    ->afterStateUpdated(fn (): mixed => $this->recalcular()),
            ])
            ->statePath('data');
    }

    public function recalcular(): void
    {
        $state = $this->form->getState();
        $fecha = $state['fecha'] ?? CarbonImmutable::now('Europe/Madrid')->format('Y-m-d');

        $this->resultado = app(PlanificadorDelDiaService::class)
            ->computar(CarbonImmutable::parse($fecha, 'Europe/Madrid'));
    }
}
