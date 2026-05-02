<?php

namespace App\Filament\Resources\AsignacionResource\Pages;

use App\Filament\Resources\AsignacionResource;
use App\Models\Asignacion;
use App\Support\TecnicoExportTransformer;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListAsignaciones extends ListRecords
{
    protected static string $resource = AsignacionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('export')
                ->label('Exportar CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $query = $this->getFilteredTableQuery()
            ->with(['averia.piv', 'tecnico']);

        $filename = 'asignaciones-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'Asignación ID',
                'Avería ID',
                'Panel ID',
                'Tipo',
                'Status',
                'Fecha asignación',
                'Técnico ID',
                'Técnico nombre',
                'Técnico email',
                'Técnico DNI',
                'Técnico NSS',
                'Técnico CCC',
                'Técnico teléfono',
                'Técnico dirección',
                'Técnico carnet',
            ]);

            $query->lazy(500)->each(function (Asignacion $asignacion) use ($output) {
                $tecnico = TecnicoExportTransformer::forAdmin($asignacion->tecnico);

                fputcsv($output, [
                    $asignacion->asignacion_id,
                    $asignacion->averia_id,
                    $asignacion->averia?->piv_id,
                    match ((int) $asignacion->tipo) {
                        Asignacion::TIPO_CORRECTIVO => 'Correctivo',
                        Asignacion::TIPO_REVISION => 'Revisión',
                        default => (string) $asignacion->tipo,
                    },
                    $asignacion->status,
                    $asignacion->fecha?->format('Y-m-d H:i:s'),
                    $tecnico['tecnico_id'],
                    $tecnico['nombre_completo'],
                    $tecnico['email'],
                    $tecnico['dni'],
                    $tecnico['n_seguridad_social'],
                    $tecnico['ccc'],
                    $tecnico['telefono'],
                    $tecnico['direccion'],
                    $tecnico['carnet_conducir'],
                ]);
            });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
