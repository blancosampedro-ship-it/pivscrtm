<?php

declare(strict_types=1);

namespace App\Filament\Resources\PivResource\Pages;

use App\Filament\Resources\PivResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPiv extends ViewRecord
{
    protected static string $resource = PivResource::class;

    /**
     * View custom para inyectar el partial de averías server-rendered.
     * Bloque 08g: reemplazo del AveriasRelationManager (lazy mount roto en
     * Filament 3 + Livewire 3, ver .github/copilot-instructions.md).
     */
    protected static string $view = 'filament.resources.piv-resource.pages.view-piv';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Volver al listado')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(static::getResource()::getUrl('index')),
            Actions\EditAction::make(),
        ];
    }
}
