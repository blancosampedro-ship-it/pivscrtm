<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\LvRutaDiaItemImagen;
use App\Models\Tecnico;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cierra items de ruta del dia y compone el cierre legacy cuando toca.
 */
final class RutaDiaItemCierreService
{
    public const NOTAS_AVERIA_STUB = 'Revisión preventiva mensual (cierre técnico desde ruta)';

    public function __construct(
        private readonly AsignacionCierreService $asignacionCierre,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{item: LvRutaDiaItem, ruta: LvRutaDia}
     */
    public function cerrar(LvRutaDiaItem $item, array $data, Tecnico $tecnico): array
    {
        return DB::transaction(function () use ($item, $data, $tecnico): array {
            $item->refresh();
            $ruta = $item->rutaDia()->lockForUpdate()->firstOrFail();

            if ((int) $ruta->tecnico_id !== (int) $tecnico->tecnico_id) {
                throw new DomainException('No autorizado: este item no pertenece a tu ruta del día.');
            }

            if (in_array($item->status, [LvRutaDiaItem::STATUS_CERRADO, LvRutaDiaItem::STATUS_NO_RESUELTO], true)) {
                throw ValidationException::withMessages(['cerrar' => 'Este item ya fue cerrado.']);
            }

            $newStatus = (string) ($data['status'] ?? LvRutaDiaItem::STATUS_CERRADO);
            if (! in_array($newStatus, [LvRutaDiaItem::STATUS_CERRADO, LvRutaDiaItem::STATUS_NO_RESUELTO], true)) {
                throw ValidationException::withMessages(['status' => 'Status inválido.']);
            }

            $causa = trim((string) ($data['causa_no_resolucion'] ?? ''));
            if ($newStatus === LvRutaDiaItem::STATUS_NO_RESUELTO && $causa === '') {
                throw ValidationException::withMessages(['causa_no_resolucion' => 'Indica la causa de no resolución.']);
            }

            $notasTecnico = trim((string) ($data['notas_tecnico'] ?? ''));

            $item->update([
                'status' => $newStatus,
                'notas_tecnico' => $notasTecnico !== '' ? $notasTecnico : null,
                'causa_no_resolucion' => $newStatus === LvRutaDiaItem::STATUS_NO_RESUELTO ? $causa : null,
                'cerrado_at' => CarbonImmutable::now('Europe/Madrid'),
            ]);

            foreach (($data['fotos'] ?? []) as $idx => $url) {
                LvRutaDiaItemImagen::create([
                    'ruta_dia_item_id' => $item->id,
                    'url' => (string) $url,
                    'posicion' => $idx + 1,
                ]);
            }

            if ($newStatus === LvRutaDiaItem::STATUS_CERRADO
                && in_array($item->tipo_item, [LvRutaDiaItem::TIPO_PREVENTIVO, LvRutaDiaItem::TIPO_CARRY_OVER], true)) {
                $this->cerrarRevisionLegacy($item, $data, $tecnico);
            }

            $this->actualizarStatusRuta($ruta);

            return [
                'item' => $item->fresh(['imagenes', 'rutaDia']),
                'ruta' => $ruta->fresh(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function cerrarRevisionLegacy(LvRutaDiaItem $item, array $data, Tecnico $tecnico): void
    {
        $revisionPendiente = $item->revisionPendiente;
        if ($revisionPendiente === null) {
            return;
        }

        $asignacion = $revisionPendiente->asignacion_id !== null
            ? Asignacion::find($revisionPendiente->asignacion_id)
            : null;

        if ($asignacion === null) {
            $averia = Averia::create([
                'piv_id' => $revisionPendiente->piv_id,
                'notas' => self::NOTAS_AVERIA_STUB,
                'status' => 1,
            ]);

            $asignacion = Asignacion::create([
                'averia_id' => $averia->averia_id,
                'tecnico_id' => $tecnico->tecnico_id,
                'tipo' => Asignacion::TIPO_REVISION,
                'fecha' => CarbonImmutable::now('Europe/Madrid')->format('Y-m-d'),
                'status' => 1,
            ]);

            $revisionPendiente->update(['asignacion_id' => $asignacion->asignacion_id]);
        }

        if ($asignacion->tecnico_id === null) {
            $asignacion->update(['tecnico_id' => $tecnico->tecnico_id]);
        }

        try {
            $this->asignacionCierre->cerrar($asignacion->fresh(), [
                'fecha' => CarbonImmutable::now('Europe/Madrid')->format('Y-m-d'),
                'ruta' => trim((string) ($data['ruta'] ?? '')) ?: null,
                'aspecto' => $data['aspecto'] ?? null,
                'funcionamiento' => $data['funcionamiento'] ?? null,
                'actuacion' => $data['actuacion'] ?? null,
                'audio' => $data['audio'] ?? null,
                'lineas' => $data['lineas'] ?? null,
                'fecha_hora' => $data['fecha_hora'] ?? null,
                'precision_paso' => $data['precision_paso'] ?? null,
                'notas' => trim((string) ($data['notas_tecnico'] ?? '')) ?: null,
            ]);
        } catch (ValidationException $exception) {
            $message = (string) collect($exception->errors())->flatten()->implode(' ');
            if (! str_contains($message, 'ya tiene')) {
                throw $exception;
            }
        }
    }

    private function actualizarStatusRuta(LvRutaDia $ruta): void
    {
        $items = $ruta->items()->get();

        if ($items->isEmpty()) {
            return;
        }

        $allClosed = $items->every(fn (LvRutaDiaItem $item): bool => in_array($item->status, [
            LvRutaDiaItem::STATUS_CERRADO,
            LvRutaDiaItem::STATUS_NO_RESUELTO,
        ], true));

        if ($allClosed && $ruta->status !== LvRutaDia::STATUS_COMPLETADA) {
            $ruta->update(['status' => LvRutaDia::STATUS_COMPLETADA]);

            return;
        }

        $anyClosed = $items->contains(fn (LvRutaDiaItem $item): bool => in_array($item->status, [
            LvRutaDiaItem::STATUS_CERRADO,
            LvRutaDiaItem::STATUS_NO_RESUELTO,
        ], true));

        if ($anyClosed && $ruta->status === LvRutaDia::STATUS_PLANIFICADA) {
            $ruta->update(['status' => LvRutaDia::STATUS_EN_PROGRESO]);
        }
    }
}
