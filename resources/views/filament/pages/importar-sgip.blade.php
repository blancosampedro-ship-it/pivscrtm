<x-filament-panels::page>
    <form wire:submit="preview" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">
            Generar preview
        </x-filament::button>
    </form>

    @if ($previewResult)
        <x-filament::section>
            <x-slot name="heading">Preview import SGIP</x-slot>

            <div class="grid gap-4 md:grid-cols-4">
                <div><strong>Filas CSV</strong><br>{{ $previewResult['rows_parsed'] }}</div>
                <div><strong>SGIP únicos</strong><br>{{ $previewResult['unique_sgip_ids'] }}</div>
                <div><strong>Nuevas</strong><br>{{ $previewResult['would_create'] }}</div>
                <div><strong>Actualizar</strong><br>{{ $previewResult['would_update'] }}</div>
                <div><strong>Marcar inactivas</strong><br>{{ $previewResult['would_mark_inactive'] }}</div>
                <div><strong>Sin match</strong><br>{{ count($previewResult['unmatched_panels']) }}</div>
                <div><strong>Ambiguas</strong><br>{{ count($previewResult['ambiguous_panels']) }}</div>
                <div><strong>SGIP duplicados</strong><br>{{ count($previewResult['duplicate_sgip_ids']) }}</div>
            </div>

            @if ($previewResult['ambiguous_panels'] || $previewResult['unmatched_panels'] || $previewResult['duplicate_sgip_ids'])
                <div class="mt-6 space-y-3 text-sm">
                    @if ($previewResult['ambiguous_panels'])
                        <p><strong>Paneles ambiguos:</strong> {{ implode(', ', $previewResult['ambiguous_panels']) }}</p>
                    @endif
                    @if ($previewResult['unmatched_panels'])
                        <p><strong>Paneles sin match:</strong> {{ implode(', ', $previewResult['unmatched_panels']) }}</p>
                    @endif
                    @if ($previewResult['duplicate_sgip_ids'])
                        <p><strong>SGIP duplicados en CSV:</strong> {{ implode(', ', $previewResult['duplicate_sgip_ids']) }}</p>
                    @endif
                </div>
            @endif

            <div class="mt-6 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                Vas a marcar {{ $previewResult['would_mark_inactive'] }} averías como inactivas si no aparecen en esta foto completa.
                Marca la confirmación del formulario antes de aplicar.
            </div>

            <div class="mt-6">
                <x-filament::modal id="confirmar-import-sgip" width="3xl">
                    <x-slot name="trigger">
                        <x-filament::button color="warning" icon="heroicon-o-check-circle">
                            Confirmar e importar
                        </x-filament::button>
                    </x-slot>

                    <x-slot name="heading">Confirmar foto completa SGIP</x-slot>

                    <div class="space-y-4 text-sm">
                        <p>
                            Vas a aplicar este CSV como snapshot completo. Se crearán {{ $previewResult['would_create'] }} averías,
                            se actualizarán {{ $previewResult['would_update'] }} y se marcarán {{ $previewResult['would_mark_inactive'] }} como inactivas.
                        </p>
                        <p>
                            La operación conserva audit trail y no borra filas. Para continuar, la casilla de confirmación del formulario debe estar marcada.
                        </p>
                    </div>

                    <x-slot name="footer">
                        <x-filament::button wire:click="confirm" color="warning" icon="heroicon-o-check-circle">
                            Aplicar importación
                        </x-filament::button>
                    </x-slot>
                </x-filament::modal>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>