<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asignacion;
use App\Models\Correctivo;
use App\Models\LvCorrectivoImagen;
use App\Models\Revision;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AsignacionCierreService
{
    /**
     * Cierra la asignación según su tipo. Es idempotente: si ya hay cierre,
     * lanza una ValidationException con clave `cerrar`.
     *
     * @param  array<string, mixed>  $data
     * @return array{model: Correctivo|Revision, imagenes: Collection<int, LvCorrectivoImagen>}
     */
    public function cerrar(Asignacion $asignacion, array $data): array
    {
        return DB::transaction(function () use ($asignacion, $data): array {
            $asignacion->refresh();

            if ((int) $asignacion->tipo === Asignacion::TIPO_CORRECTIVO && $asignacion->correctivo()->exists()) {
                throw ValidationException::withMessages([
                    'cerrar' => 'Esta asignación ya tiene un correctivo registrado.',
                ]);
            }

            if ((int) $asignacion->tipo === Asignacion::TIPO_REVISION && $asignacion->revision()->exists()) {
                throw ValidationException::withMessages([
                    'cerrar' => 'Esta asignación ya tiene una revisión registrada.',
                ]);
            }

            $result = match ((int) $asignacion->tipo) {
                Asignacion::TIPO_CORRECTIVO => $this->cerrarCorrectivo($asignacion, $data),
                Asignacion::TIPO_REVISION => $this->cerrarRevision($asignacion, $data),
                default => throw ValidationException::withMessages([
                    'cerrar' => 'Asignación con tipo desconocido. No se puede cerrar desde aquí.',
                ]),
            };

            // NO tocamos averia.notas: pertenece al operador que reportó la avería.
            $asignacion->update(['status' => 2]);

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{model: Correctivo, imagenes: Collection<int, LvCorrectivoImagen>}
     */
    private function cerrarCorrectivo(Asignacion $asignacion, array $data): array
    {
        $correctivo = Correctivo::create([
            'tecnico_id' => $asignacion->tecnico_id,
            'asignacion_id' => $asignacion->asignacion_id,
            'tiempo' => $data['tiempo'] ?? null,
            'recambios' => $data['recambios'],
            'diagnostico' => $data['diagnostico'],
            'estado_final' => $data['estado_final'] ?? 'OK',
            'contrato' => $data['contrato'] ?? false,
            'facturar_horas' => $data['facturar_horas'] ?? false,
            'facturar_desplazamiento' => $data['facturar_desplazamiento'] ?? false,
            'facturar_recambios' => $data['facturar_recambios'] ?? false,
        ]);

        foreach (($data['fotos'] ?? []) as $idx => $url) {
            LvCorrectivoImagen::create([
                'correctivo_id' => $correctivo->correctivo_id,
                'url' => (string) $url,
                'posicion' => $idx + 1,
            ]);
        }

        return [
            'model' => $correctivo,
            'imagenes' => $correctivo->imagenes()->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{model: Revision, imagenes: Collection<int, LvCorrectivoImagen>}
     */
    private function cerrarRevision(Asignacion $asignacion, array $data): array
    {
        $revision = Revision::create([
            'tecnico_id' => $asignacion->tecnico_id,
            'asignacion_id' => $asignacion->asignacion_id,
            'fecha' => filled($data['fecha'] ?? null) ? Carbon::parse($data['fecha'])->format('Y-m-d') : null,
            'ruta' => $data['ruta'] ?? null,
            'aspecto' => $data['aspecto'] ?? null,
            'funcionamiento' => $data['funcionamiento'] ?? null,
            'actuacion' => $data['actuacion'] ?? null,
            'audio' => $data['audio'] ?? null,
            'lineas' => $data['lineas'] ?? null,
            'fecha_hora' => $data['fecha_hora'] ?? null,
            'precision_paso' => $data['precision_paso'] ?? null,
            'notas' => $data['notas'] ?? null,
        ]);

        return [
            'model' => $revision,
            'imagenes' => new Collection(),
        ];
    }
}