<?php

declare(strict_types=1);

namespace App\Filament\Resources\LvRevisionPendienteResource\Pages;

use App\Filament\Resources\LvRevisionPendienteResource;
use Filament\Resources\Pages\ListRecords;

final class ListLvRevisionPendientes extends ListRecords
{
    protected static string $resource = LvRevisionPendienteResource::class;
}
