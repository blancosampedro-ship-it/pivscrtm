<?php

declare(strict_types=1);

namespace App\Filament\Resources\LvReportePeriodicoResource\Pages;

use App\Filament\Resources\LvReportePeriodicoResource;
use Filament\Resources\Pages\ListRecords;

final class ListLvReportesPeriodicos extends ListRecords
{
    protected static string $resource = LvReportePeriodicoResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
