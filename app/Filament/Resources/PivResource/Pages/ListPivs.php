<?php

namespace App\Filament\Resources\PivResource\Pages;

use App\Filament\Resources\PivResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPivs extends ListRecords
{
    protected static string $resource = PivResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
