<?php

namespace App\Filament\Resources\AveriaResource\Pages;

use App\Filament\Resources\AveriaResource;
use App\Models\Averia;
use App\Support\TecnicoExportTransformer;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListAverias extends ListRecords
{
    protected static string $resource = AveriaResource::class;

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
            ->with(['piv', 'asignacion.tecnico']);

        $filename = 'averias-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'Avería ID',
                'Panel ID',
                'Parada',
                'Fecha',
                'Status',
                'Notas',
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

            $query->lazy(500)->each(function (Averia $averia) use ($output) {
                $tecnico = TecnicoExportTransformer::forAdmin($averia->asignacion?->tecnico);

                fputcsv($output, [
                    $averia->averia_id,
                    $averia->piv_id,
                    $averia->piv?->parada_cod,
                    $averia->fecha?->format('Y-m-d H:i:s'),
                    $averia->status,
                    (string) $averia->notas,
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
