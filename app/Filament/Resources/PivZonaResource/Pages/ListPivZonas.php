<?php

declare(strict_types=1);

namespace App\Filament\Resources\PivZonaResource\Pages;

use App\Filament\Resources\PivZonaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPivZonas extends ListRecords
{
    protected static string $resource = PivZonaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Crear zona'),
        ];
    }
}
