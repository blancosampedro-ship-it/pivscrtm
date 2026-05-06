<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\LvRutaDiaResource;
use App\Models\Tecnico;
use App\Services\PlanificadorDelDiaService;
use App\Services\RutaDiaSnapshotService;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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
                    ->helperText('Solo filtra preventivos y carry overs. Las averías ICCA muestran siempre las abiertas en SGIP.')
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('crearRutaDia')
                ->label('Crear ruta del día')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('primary')
                ->visible(fn (): bool => ($this->resultado['total_items'] ?? 0) > 0)
                ->form([
                    Select::make('tecnico_id')
                        ->label('Técnico')
                        ->options(fn (): array => Tecnico::query()
                            ->where('status', 1)
                            ->orderBy('nombre_completo')
                            ->pluck('nombre_completo', 'tecnico_id')
                            ->all())
                        ->searchable()
                        ->required(),
                    Checkbox::make('incluir_ambiguas')
                        ->label('Incluir averías ambiguas (sin piv_id resuelto)')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    $state = $this->form->getState();
                    $fecha = CarbonImmutable::parse($state['fecha'], 'Europe/Madrid');

                    try {
                        $ruta = app(RutaDiaSnapshotService::class)->snapshot(
                            (int) $data['tecnico_id'],
                            $fecha,
                            auth()->user(),
                            (bool) ($data['incluir_ambiguas'] ?? true),
                        );
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title('No se pudo crear la ruta')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Ruta del día creada con '.$ruta->items->count().' items')
                        ->success()
                        ->send();

                    $this->redirect(LvRutaDiaResource::getUrl('edit', ['record' => $ruta]));
                }),
        ];
    }
}
