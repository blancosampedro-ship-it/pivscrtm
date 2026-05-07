<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LvDerivacion;
use App\Models\LvRutaDiaItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class DerivacionService
{
    public function derivar(LvRutaDiaItem $item, array $data, User $admin): LvDerivacion
    {
        $validated = Validator::make($data, [
            'tipo_causa' => ['required', 'string', Rule::in(LvDerivacion::CAUSAS)],
            'causa_otros_texto' => ['nullable', 'string', 'max:500', 'required_if:tipo_causa,'.LvDerivacion::CAUSA_OTROS],
            'actor_responsable' => ['required', 'string', Rule::in(LvDerivacion::ACTORES)],
            'actor_notas' => ['nullable', 'string', 'max:200'],
            'notas_derivacion' => ['nullable', 'string'],
        ])->validate();

        if (($validated['tipo_causa'] ?? null) === LvDerivacion::CAUSA_OTROS && trim((string) ($validated['causa_otros_texto'] ?? '')) === '') {
            Validator::make(['causa_otros_texto' => ''], ['causa_otros_texto' => ['required']])->validate();
        }

        if ($item->tieneDerivacionAbierta()) {
            throw new DomainException('Item ya tiene derivación abierta');
        }

        if (! in_array($item->status, [LvRutaDiaItem::STATUS_PENDIENTE, LvRutaDiaItem::STATUS_CERRADO], true)) {
            throw new DomainException('El item no está en un estado derivable');
        }

        return DB::transaction(function () use ($item, $validated, $admin): LvDerivacion {
            $derivacion = LvDerivacion::create([
                'lv_ruta_dia_item_id' => $item->id,
                'tipo_causa' => $validated['tipo_causa'],
                'causa_otros_texto' => $validated['causa_otros_texto'] ?? null,
                'actor_responsable' => $validated['actor_responsable'],
                'actor_notas' => $validated['actor_notas'] ?? null,
                'notas_derivacion' => $validated['notas_derivacion'] ?? null,
                'fecha_derivacion' => now('Europe/Madrid'),
                'derivado_por_user_id' => $admin->id,
                'status' => LvDerivacion::STATUS_PENDIENTE_TERCERO,
            ]);

            $item->forceFill(['status' => LvRutaDiaItem::STATUS_DERIVADO])->save();

            return $derivacion;
        });
    }

    public function resolverExternamente(LvDerivacion $deriv, array $data, User $admin): LvDerivacion
    {
        return $this->cerrar($deriv, $data, $admin, LvDerivacion::STATUS_RESUELTO_EXTERNO, null);
    }

    public function devolverARuta(LvDerivacion $deriv, array $data, User $admin): LvDerivacion
    {
        return $this->cerrar($deriv, $data, $admin, LvDerivacion::STATUS_DEVUELTO_A_RUTA, LvRutaDiaItem::STATUS_PENDIENTE);
    }

    public function cancelar(LvDerivacion $deriv, array $data, User $admin): LvDerivacion
    {
        return $this->cerrar($deriv, $data, $admin, LvDerivacion::STATUS_CANCELADA, LvRutaDiaItem::STATUS_PENDIENTE);
    }

    private function cerrar(LvDerivacion $deriv, array $data, User $admin, string $status, ?string $itemStatus): LvDerivacion
    {
        $validated = Validator::make($data, [
            'notas' => ['required', 'string'],
        ])->validate();

        if (! $deriv->isAbierta()) {
            throw new DomainException('La derivación ya está cerrada');
        }

        return DB::transaction(function () use ($deriv, $validated, $admin, $status, $itemStatus): LvDerivacion {
            $deriv->forceFill([
                'status' => $status,
                'fecha_resolucion' => now('Europe/Madrid'),
                'resuelto_por_user_id' => $admin->id,
                'resuelto_notas' => $validated['notas'],
            ])->save();

            if ($itemStatus !== null) {
                $deriv->item()->firstOrFail()->forceFill(['status' => $itemStatus])->save();
            }

            return $deriv;
        });
    }
}
