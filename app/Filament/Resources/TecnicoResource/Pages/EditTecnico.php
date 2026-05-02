<?php

declare(strict_types=1);

namespace App\Filament\Resources\TecnicoResource\Pages;

use App\Filament\Resources\TecnicoResource;
use Filament\Resources\Pages\EditRecord;

class EditTecnico extends EditRecord
{
    protected static string $resource = TecnicoResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Mismo principio que CreateTecnico, pero condicional: si admin no
        // tocó el campo, no sobreescribimos `clave` y conserva la actual.
        // CRÍTICO: se escribe SHA1 legacy, nunca bcrypt; ver ADR-0003.
        $plain = $data['password_plain'] ?? null;
        unset($data['password_plain']);

        if ($plain) {
            $data['clave'] = sha1((string) $plain);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['clave']);

        return $data;
    }
}
