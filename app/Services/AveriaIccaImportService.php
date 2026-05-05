<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LvAveriaIcca;
use App\Models\Piv;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Importa CSV SGIP/ICCA como foto completa del momento.
 */
final class AveriaIccaImportService
{
    public const COLUMNAS_OBLIGATORIAS = ['Id', 'Categoría', 'Resumen', 'Estado', 'Descripción', 'NOTAS', 'Asignada a'];

    /**
     * @return array{rows_parsed: int, unique_sgip_ids: int, duplicate_sgip_ids: list<string>, would_create: int, would_update: int, would_mark_inactive: int, unmatched_panels: list<string>, ambiguous_panels: list<string>}
     */
    public function preview(UploadedFile $csv): array
    {
        $rows = $this->parseCsv((string) $csv->getRealPath());
        $uniqueRows = $this->uniqueRowsBySgipId($rows);
        $sgipIds = array_column($uniqueRows, 'sgip_id');

        $existing = LvAveriaIcca::query()
            ->whereIn('sgip_id', $sgipIds)
            ->pluck('sgip_id')
            ->all();
        $existingSet = array_flip($existing);

        $wouldCreate = 0;
        $wouldUpdate = 0;
        $unmatched = [];
        $ambiguous = [];

        foreach ($uniqueRows as $row) {
            if (isset($existingSet[$row['sgip_id']])) {
                $wouldUpdate++;
            } else {
                $wouldCreate++;
            }

            $resolution = $this->resolvePivId($row['panel_id_sgip']);
            if ($resolution === 'unmatched') {
                $unmatched[] = $row['panel_id_sgip'];
            } elseif ($resolution === 'ambiguous') {
                $ambiguous[] = $row['panel_id_sgip'];
            }
        }

        $wouldMarkInactive = LvAveriaIcca::query()
            ->where('activa', true)
            ->whereNotIn('sgip_id', $sgipIds)
            ->count();

        return [
            'rows_parsed' => count($rows),
            'unique_sgip_ids' => count($uniqueRows),
            'duplicate_sgip_ids' => $this->duplicateSgipIds($rows),
            'would_create' => $wouldCreate,
            'would_update' => $wouldUpdate,
            'would_mark_inactive' => $wouldMarkInactive,
            'unmatched_panels' => array_values(array_unique($unmatched)),
            'ambiguous_panels' => array_values(array_unique($ambiguous)),
        ];
    }

    /**
     * @return array{created: int, updated: int, marked_inactive: int, duplicate_sgip_ids: list<string>, errors: list<string>}
     */
    public function import(UploadedFile $csv, User $admin): array
    {
        $filename = $csv->getClientOriginalName();
        $importTime = CarbonImmutable::now();
        $rows = $this->parseCsv((string) $csv->getRealPath());
        $uniqueRows = $this->uniqueRowsBySgipId($rows);
        $sgipIds = array_column($uniqueRows, 'sgip_id');
        $duplicateSgipIds = $this->duplicateSgipIds($rows);

        return DB::transaction(function () use ($uniqueRows, $sgipIds, $admin, $filename, $importTime, $duplicateSgipIds): array {
            $created = 0;
            $updated = 0;

            foreach ($uniqueRows as $row) {
                $payload = [
                    'panel_id_sgip' => $row['panel_id_sgip'],
                    'piv_id' => $this->resolveOrNull($row['panel_id_sgip']),
                    'categoria' => $row['categoria'],
                    'descripcion' => $row['descripcion'] !== '' ? $row['descripcion'] : null,
                    'notas' => $row['notas'] !== '' ? $row['notas'] : null,
                    'estado_externo' => $row['estado_externo'],
                    'asignada_a' => $row['asignada_a'],
                    'activa' => true,
                    'fecha_import' => $importTime,
                    'archivo_origen' => $filename,
                    'imported_by_user_id' => $admin->id,
                    'marked_inactive_at' => null,
                ];

                $existing = LvAveriaIcca::query()->where('sgip_id', $row['sgip_id'])->first();

                if ($existing === null) {
                    LvAveriaIcca::create(array_merge(['sgip_id' => $row['sgip_id']], $payload));
                    $created++;
                } else {
                    $existing->update($payload);
                    $updated++;
                }
            }

            $markedInactive = LvAveriaIcca::query()
                ->where('activa', true)
                ->whereNotIn('sgip_id', $sgipIds)
                ->update([
                    'activa' => false,
                    'marked_inactive_at' => $importTime,
                    'updated_at' => $importTime,
                ]);

            return [
                'created' => $created,
                'updated' => $updated,
                'marked_inactive' => $markedInactive,
                'duplicate_sgip_ids' => $duplicateSgipIds,
                'errors' => [],
            ];
        });
    }

    /**
     * @return list<array{sgip_id: string, panel_id_sgip: string, categoria: string, descripcion: string, notas: string, estado_externo: string, asignada_a: string}>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir el CSV: {$path}");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            throw new RuntimeException('CSV vacío o malformado');
        }

        $header = array_map(fn (string $column): string => trim(str_replace("\xEF\xBB\xBF", '', $column)), $header);
        $missing = array_diff(self::COLUMNAS_OBLIGATORIAS, $header);

        if ($missing !== []) {
            fclose($handle);

            throw new RuntimeException('CSV missing columns: '.implode(', ', $missing));
        }

        $indexes = array_flip($header);
        $rows = [];

        while (($record = fgetcsv($handle)) !== false) {
            if (count(array_filter($record, fn (?string $value): bool => filled($value))) === 0) {
                continue;
            }

            $rows[] = [
                'sgip_id' => trim($record[$indexes['Id']] ?? ''),
                'panel_id_sgip' => trim($record[$indexes['Resumen']] ?? ''),
                'categoria' => $this->normalizeCategoria(trim($record[$indexes['Categoría']] ?? '')),
                'descripcion' => trim($record[$indexes['Descripción']] ?? ''),
                'notas' => trim($record[$indexes['NOTAS']] ?? ''),
                'estado_externo' => trim($record[$indexes['Estado']] ?? ''),
                'asignada_a' => trim($record[$indexes['Asignada a']] ?? ''),
            ];
        }

        fclose($handle);

        return array_values(array_filter($rows, fn (array $row): bool => $row['sgip_id'] !== ''));
    }

    /**
     * @param  list<array{sgip_id: string, panel_id_sgip: string, categoria: string, descripcion: string, notas: string, estado_externo: string, asignada_a: string}>  $rows
     * @return list<array{sgip_id: string, panel_id_sgip: string, categoria: string, descripcion: string, notas: string, estado_externo: string, asignada_a: string}>
     */
    private function uniqueRowsBySgipId(array $rows): array
    {
        $unique = [];

        foreach ($rows as $row) {
            $unique[$row['sgip_id']] = $row;
        }

        return array_values($unique);
    }

    /**
     * @param  list<array{sgip_id: string}>  $rows
     * @return list<string>
     */
    private function duplicateSgipIds(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['sgip_id']] = ($counts[$row['sgip_id']] ?? 0) + 1;
        }

        return array_values(array_keys(array_filter($counts, fn (int $count): bool => $count > 1)));
    }

    /**
     * @return int|'ambiguous'|'unmatched'
     */
    private function resolvePivId(string $panelIdSgip): int|string
    {
        if (! preg_match('/(\d+)/', $panelIdSgip, $matches)) {
            return 'unmatched';
        }

        $panelNumber = $matches[1];

        $exact = Piv::query()
            ->where('parada_cod', $panelNumber)
            ->pluck('piv_id')
            ->all();

        if (count($exact) === 1) {
            return (int) $exact[0];
        }

        if (count($exact) > 1) {
            return 'ambiguous';
        }

        $byNumericCast = Piv::query()
            ->whereRaw('CAST(parada_cod AS UNSIGNED) = ?', [(int) $panelNumber])
            ->pluck('piv_id')
            ->all();

        if (count($byNumericCast) === 1) {
            return (int) $byNumericCast[0];
        }

        if (count($byNumericCast) > 1) {
            return 'ambiguous';
        }

        return 'unmatched';
    }

    private function resolveOrNull(string $panelIdSgip): ?int
    {
        $resolution = $this->resolvePivId($panelIdSgip);

        if (is_int($resolution)) {
            return $resolution;
        }

        Log::info("AveriaIccaImport: panel {$resolution} for {$panelIdSgip}");

        return null;
    }

    private function normalizeCategoria(string $categoria): string
    {
        if (in_array($categoria, LvAveriaIcca::CATEGORIAS_CONOCIDAS, true)) {
            return $categoria;
        }

        return LvAveriaIcca::CAT_OTRAS;
    }
}
