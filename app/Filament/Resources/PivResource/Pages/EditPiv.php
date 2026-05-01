<?php

namespace App\Filament\Resources\PivResource\Pages;

use App\Filament\Resources\PivResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPiv extends EditRecord
{
    protected static string $resource = PivResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
