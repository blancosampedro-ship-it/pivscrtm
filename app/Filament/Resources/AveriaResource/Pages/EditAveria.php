<?php

namespace App\Filament\Resources\AveriaResource\Pages;

use App\Filament\Resources\AveriaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAveria extends EditRecord
{
    protected static string $resource = AveriaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
