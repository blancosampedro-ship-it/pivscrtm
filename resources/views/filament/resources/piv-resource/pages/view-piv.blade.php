<x-filament-panels::page
    @class([
        'fi-resource-view-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
        'fi-resource-record-' . $record->getKey(),
    ])
>
    {{-- Infolist principal del panel --}}
    @if ($this->hasInfolist())
        {{ $this->infolist }}
    @else
        <div wire:key="{{ $this->getId() }}.forms.{{ $this->getFormStatePath() }}">
            {{ $this->form }}
        </div>
    @endif

    {{-- Histórico de averías (vista custom, sin RelationManager).
         Bloque 08g: reemplazo del RM lazy por tabla server-rendered.
         Razón: lazy mount de Livewire/Filament no rehidrata $ownerRecord
         antes de bootedInteractsWithTable, crash "::class on null" en
         HasRecords::76. Documentado en .github/copilot-instructions.md. --}}
    @include('filament.resources.piv-resource.partials.averias-table', [
        'piv' => $record,
    ])
</x-filament-panels::page>
