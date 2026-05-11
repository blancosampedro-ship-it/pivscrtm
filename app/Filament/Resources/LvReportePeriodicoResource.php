<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LvReportePeriodicoResource\Pages;
use App\Models\LvReportePeriodico;
use App\Services\ReportePeriodicoService;
use DomainException;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\ActionSize;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class LvReportePeriodicoResource extends Resource
{
    protected static ?string $model = LvReportePeriodico::class;

    protected static ?string $slug = 'reportes-periodicos';

    protected static ?string $navigationLabel = 'Reportes';

    protected static ?string $modelLabel = 'reporte';

    protected static ?string $pluralModelLabel = 'reportes';

    protected static ?string $navigationGroup = 'Planificación';

    protected static ?int $navigationSort = 100;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('generadoPor:id,name');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->striped()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('generated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === LvReportePeriodico::TIPO_MENSUAL ? 'primary' : 'warning'),
                Tables\Columns\TextColumn::make('periodo')
                    ->label('Periodo')
                    ->state(fn (LvReportePeriodico $record): string => $record->periodoLabel())
                    ->sortable(['anyo', 'mes']),
                Tables\Columns\TextColumn::make('generated_at')
                    ->label('Generado')
                    ->dateTime('Y-m-d H:i')
                    ->extraAttributes(['data-mono' => true])
                    ->sortable(),
                Tables\Columns\TextColumn::make('generadoPor.name')
                    ->label('Generado por')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options([
                        LvReportePeriodico::TIPO_MENSUAL => 'Mensual',
                        LvReportePeriodico::TIPO_ANUAL => 'Anual',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('tipo', $data['value'])
                        : $query),
                Tables\Filters\SelectFilter::make('anyo')
                    ->label('Año')
                    ->options(fn (): array => LvReportePeriodico::query()
                        ->select('anyo')
                        ->distinct()
                        ->orderByDesc('anyo')
                        ->pluck('anyo', 'anyo')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('anyo', (int) $data['value'])
                        : $query),
            ])
            ->headerActions([
                Action::make('generarReporte')
                    ->label('Generar reporte')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form(self::periodoFormSchema())
                    ->action(function (array $data): void {
                        self::runGeneration((string) $data['tipo'], (int) $data['anyo'], isset($data['mes']) ? (int) $data['mes'] : null);
                    }),
            ])
            ->actions([
                Action::make('descargarPdf')
                    ->label('Descargar PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->size(ActionSize::Small)
                    ->action(fn (LvReportePeriodico $record): ?BinaryFileResponse => self::download($record, 'pdf')),
                Action::make('descargarExcel')
                    ->label('Descargar Excel')
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->size(ActionSize::Small)
                    ->action(fn (LvReportePeriodico $record): ?BinaryFileResponse => self::download($record, 'xlsx')),
                Action::make('regenerar')
                    ->label('Regenerar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->size(ActionSize::Small)
                    ->requiresConfirmation()
                    ->modalDescription('Vas a regenerar este reporte. Los archivos PDF/Excel actuales se sobrescribirán. ¿Continuar?')
                    ->action(function (LvReportePeriodico $record): void {
                        self::runGeneration($record->tipo, $record->anyo, $record->mes);
                    }),
                Tables\Actions\DeleteAction::make()
                    ->label('Eliminar')
                    ->size(ActionSize::Small)
                    ->before(fn (LvReportePeriodico $record): bool => File::delete([$record->pdfFullPath(), $record->xlsxFullPath()])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLvReportesPeriodicos::route('/'),
        ];
    }

    /** @return array<int, Forms\Components\Component> */
    private static function periodoFormSchema(): array
    {
        return [
            Forms\Components\Select::make('tipo')
                ->label('Tipo')
                ->options([
                    LvReportePeriodico::TIPO_MENSUAL => 'Mensual',
                    LvReportePeriodico::TIPO_ANUAL => 'Anual',
                ])
                ->required()
                ->live(),
            Forms\Components\Select::make('anyo')
                ->label('Año')
                ->options(self::yearOptions())
                ->default(Carbon::now('Europe/Madrid')->year)
                ->required(),
            Forms\Components\Select::make('mes')
                ->label('Mes')
                ->options(self::monthOptions())
                ->visible(fn (Forms\Get $get): bool => $get('tipo') === LvReportePeriodico::TIPO_MENSUAL)
                ->required(fn (Forms\Get $get): bool => $get('tipo') === LvReportePeriodico::TIPO_MENSUAL),
        ];
    }

    private static function runGeneration(string $tipo, int $anyo, ?int $mes): void
    {
        try {
            $service = app(ReportePeriodicoService::class);
            $report = $tipo === LvReportePeriodico::TIPO_MENSUAL
                ? $service->generarMensual($anyo, (int) $mes, auth()->user())
                : $service->generarAnual($anyo, auth()->user());

            Notification::make()
                ->title('Reporte generado')
                ->body('Reporte generado. '.count($report->metadata_json, COUNT_RECURSIVE).' KPIs calculados.')
                ->success()
                ->send();
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }
    }

    private static function download(LvReportePeriodico $record, string $type): ?BinaryFileResponse
    {
        $path = $type === 'pdf' ? $record->pdfFullPath() : $record->xlsxFullPath();

        if (! File::exists($path)) {
            Notification::make()
                ->title('Archivo no encontrado en filesystem. Regenera el reporte.')
                ->danger()
                ->send();

            return null;
        }

        return response()->download($path);
    }

    private static function yearOptions(): array
    {
        $current = Carbon::now('Europe/Madrid')->year;

        return collect(range(2024, $current + 1))
            ->reverse()
            ->mapWithKeys(fn (int $year): array => [$year => (string) $year])
            ->all();
    }

    private static function monthOptions(): array
    {
        return [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];
    }
}
