<?php

declare(strict_types=1);

namespace App\Filament\Resources\PivResource\Pages;

use App\Filament\Resources\PivResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPiv extends ViewRecord
{
    protected static string $resource = PivResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
