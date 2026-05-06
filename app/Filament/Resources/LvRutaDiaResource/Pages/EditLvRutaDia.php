<?php

declare(strict_types=1);

namespace App\Filament\Resources\LvRutaDiaResource\Pages;

use App\Filament\Resources\LvRutaDiaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

final class EditLvRutaDia extends EditRecord
{
    protected static string $resource = LvRutaDiaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Borrar ruta')
                ->requiresConfirmation(),
        ];
    }
}
