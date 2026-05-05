<?php

declare(strict_types=1);

namespace App\Filament\Resources\PivRutaResource\Pages;

use App\Filament\Resources\PivRutaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListPivRutas extends ListRecords
{
    protected static string $resource = PivRutaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Crear ruta'),
        ];
    }
}
