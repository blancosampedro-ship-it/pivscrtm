<?php

declare(strict_types=1);

namespace App\Filament\Resources\LvRutaDiaResource\Pages;

use App\Filament\Resources\LvRutaDiaResource;
use Filament\Resources\Pages\ListRecords;

final class ListLvRutaDias extends ListRecords
{
    protected static string $resource = LvRutaDiaResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
