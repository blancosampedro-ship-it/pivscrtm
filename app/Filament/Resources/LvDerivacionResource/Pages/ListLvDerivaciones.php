<?php

declare(strict_types=1);

namespace App\Filament\Resources\LvDerivacionResource\Pages;

use App\Filament\Resources\LvDerivacionResource;
use Filament\Resources\Pages\ListRecords;

final class ListLvDerivaciones extends ListRecords
{
    protected static string $resource = LvDerivacionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
