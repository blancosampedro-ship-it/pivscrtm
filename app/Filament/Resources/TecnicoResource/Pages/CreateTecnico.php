<?php

declare(strict_types=1);

namespace App\Filament\Resources\TecnicoResource\Pages;

use App\Filament\Resources\TecnicoResource;
use App\Models\Tecnico;
use Filament\Resources\Pages\CreateRecord;

class CreateTecnico extends CreateRecord
{
    protected static string $resource = TecnicoResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // El form entrega la password plana en `password_plain` solo hasta este handler.
        // Aquí la convertimos a SHA1 y la escribimos en la columna legacy `clave`.
        // CRÍTICO: NO bcrypt. LegacyHashGuard espera SHA1 aquí; la migración a
        // bcrypt ocurre lazy en lv_users durante el primer login PWA del técnico.
        $plain = $data['password_plain'] ?? null;
        unset($data['password_plain']);

        if ($plain) {
            $data['clave'] = sha1((string) $plain);
        }

        $data['tecnico_id'] ??= ((int) Tecnico::max('tecnico_id')) + 1;

        return $data;
    }
}
