<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ReportePeriodicoExcelBuilder
{
    public function save(array $kpis, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('Winfin Sistemas')
            ->setTitle('Reporte de Mantenimiento PIVs Madrid '.$kpis['periodo']['label']);

        $this->buildResumen($spreadsheet->getActiveSheet(), $kpis);
        $this->buildTableSheet($spreadsheet, 'Averías ICCA', ['SGIP ID', 'Panel', 'Municipio', 'Categoría', 'Descripción', 'Fecha import', 'Activa', 'Resuelto'], $kpis['tablas']['averias_icca']);
        $this->buildTableSheet($spreadsheet, 'Revisiones preventivas', ['PIV ID', 'Parada', 'Municipio', 'Periodo', 'Status', 'Fecha planificada', 'Decision', 'Asignación creada'], $kpis['tablas']['revisiones']);
        $this->buildTableSheet($spreadsheet, 'Derivaciones', ['Item ID', 'Panel', 'Causa', 'Actor', 'Notas actor', 'Status', 'Fecha derivación', 'Fecha resolución'], $kpis['tablas']['derivaciones']);
        $this->buildTableSheet($spreadsheet, 'Detalle paneles', ['PIV ID', 'Parada', 'Municipio', 'Ruta', '# averías mes', '# revisiones', '# derivaciones'], $kpis['detalle_paneles']);

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    private function buildResumen(Worksheet $sheet, array $kpis): void
    {
        $sheet->setTitle('Resumen');
        $rows = [
            ['Periodo', $kpis['periodo']['label']],
            ['Rango desde', $kpis['periodo']['rango_fechas']['from']],
            ['Rango hasta', $kpis['periodo']['rango_fechas']['to']],
            ['Averías ICCA importadas', $kpis['volumen']['averias_icca_importadas']],
            ['Averías ICCA activas fin periodo', $kpis['volumen']['averias_icca_activas_fin_periodo']],
            ['Revisiones planificadas', $kpis['volumen']['revisiones_planificadas']],
            ['Revisiones completadas', $kpis['volumen']['revisiones_completadas']],
            ['Rutas día ejecutadas', $kpis['volumen']['rutas_dia_ejecutadas']],
            ['Items cerrados total', $kpis['volumen']['items_cerrados_total']],
            ['% revisiones completadas', $kpis['resolucion']['pct_revisiones_completadas']],
            ['% items resueltos en ruta', $kpis['resolucion']['pct_items_resueltos_en_ruta']],
            ['% items no resueltos', $kpis['resolucion']['pct_items_no_resueltos']],
            ['% items derivados', $kpis['resolucion']['pct_items_derivados']],
            ['Tiempo medio cierre horas', $kpis['resolucion']['tiempo_medio_cierre_horas']],
            ['Derivaciones total', $kpis['derivaciones']['total']],
            ['Tiempo medio resolución días', $kpis['derivaciones']['tiempo_medio_resolucion_dias']],
        ];

        $sheet->fromArray([['KPI', 'Valor'], ...$rows]);
        $this->styleTable($sheet, count($rows) + 1, 2);
    }

    /** @param list<array<string, mixed>> $rows */
    private function buildTableSheet(Spreadsheet $spreadsheet, string $title, array $headers, array $rows): void
    {
        $sheet = new Worksheet($spreadsheet, mb_substr($title, 0, 31));
        $spreadsheet->addSheet($sheet);
        $sheet->fromArray([$headers]);

        foreach ($rows as $index => $row) {
            $sheet->fromArray(array_values($row), null, 'A'.($index + 2));
        }

        $this->styleTable($sheet, max(count($rows) + 1, 1), count($headers));
    }

    private function styleTable(Worksheet $sheet, int $rowCount, int $columnCount): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $range = 'A1:'.$lastColumn.max(1, $rowCount);

        $sheet->freezePane('A2');
        $sheet->getStyle('A1:'.$lastColumn.'1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D3F8C']],
        ]);
        $sheet->getStyle($range)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9E2F3']]],
        ]);

        for ($column = 1; $column <= $columnCount; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }
    }
}
