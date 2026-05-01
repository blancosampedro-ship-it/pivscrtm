<?php

namespace App\Filament\Resources\AveriaResource\Pages;

use App\Filament\Resources\AveriaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAverias extends ListRecords
{
    protected static string $resource = AveriaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
