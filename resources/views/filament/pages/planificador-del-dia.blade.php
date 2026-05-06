<x-filament-panels::page>
    <form class="mb-6">
        {{ $this->form }}
    </form>

    @if ($resultado)
        <div class="mb-6 grid gap-4 md:grid-cols-3">
            <x-filament::section>
                <x-slot name="heading">Total items hoy</x-slot>
                <div class="text-3xl font-semibold">{{ $resultado['total_items'] }}</div>
                <div class="text-sm text-gray-500">
                    {{ $resultado['total_correctivos'] }} correctivos ·
                    {{ $resultado['total_preventivos'] }} preventivos ·
                    {{ $resultado['total_carry_overs'] }} carry overs
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Distribución por ruta</x-slot>
                <div class="space-y-1 text-sm">
                    @foreach ($resultado['distribucion'] as $codigo => $count)
                        <div class="flex justify-between gap-4">
                            <span data-mono>{{ $codigo }}</span>
                            <span class="font-semibold">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Ambiguous</x-slot>
                <div class="text-3xl font-semibold">{{ $resultado['ambiguous_count'] }}</div>
                <div class="text-sm text-gray-500">averías ICCA sin piv_id resuelto</div>
            </x-filament::section>
        </div>

        <div class="space-y-6">
            @foreach ($resultado['grupos'] as $grupo)
                <x-filament::section>
                    <x-slot name="heading">
                        <span class="flex flex-wrap items-center gap-2">
                            @if ($grupo['ruta_color_hint'])
                                <span class="inline-block h-3 w-3" style="background: {{ $grupo['ruta_color_hint'] }};"></span>
                            @endif
                            <span data-mono>{{ $grupo['ruta_codigo'] }}</span>
                            <span>· {{ $grupo['ruta_nombre'] }}</span>
                            <span class="text-sm text-gray-500">({{ $grupo['items_count'] }} items)</span>
                        </span>
                    </x-slot>

                    @if ($grupo['items_count'] === 0)
                        <p class="text-sm text-gray-500">Sin items para esta ruta el día seleccionado.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="text-left text-gray-500">
                                    <tr>
                                        <th class="py-1 pr-2 font-semibold">Tipo</th>
                                        <th class="py-1 pr-2 font-semibold">Panel</th>
                                        <th class="py-1 pr-2 font-semibold">Municipio</th>
                                        <th class="py-1 pr-2 font-semibold">Km</th>
                                        <th class="py-1 pr-2 font-semibold">Detalle</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($grupo['items'] as $item)
                                        <tr class="border-t border-gray-200">
                                            <td class="py-2 pr-2">
                                                @if ($item['tipo'] === 'correctivo')
                                                    <span class="inline-flex items-center bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800">Correctivo</span>
                                                @elseif ($item['tipo'] === 'preventivo')
                                                    <span class="inline-flex items-center bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">Preventivo</span>
                                                @else
                                                    <span class="inline-flex items-center bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Carry over</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pr-2" data-mono>
                                                {{ $item['parada_cod'] ?? '—' }}
                                                @if ($item['piv_id'] === null)
                                                    <span class="ml-1 text-xs text-amber-600">(sin piv)</span>
                                                @endif
                                            </td>
                                            <td class="py-2 pr-2" data-mono>
                                                {{ $item['municipio_modulo_id'] ?? '—' }}
                                            </td>
                                            <td class="py-2 pr-2" data-mono>
                                                {{ $item['km_desde_ciempozuelos'] !== null ? $item['km_desde_ciempozuelos'].' km' : '—' }}
                                            </td>
                                            <td class="py-2 pr-2 text-gray-700">
                                                @if ($item['categoria'])
                                                    <strong>{{ $item['categoria'] }}</strong>
                                                @endif
                                                @if ($item['fecha_planificada'])
                                                    <span data-mono>· {{ $item['fecha_planificada'] }}</span>
                                                @endif
                                                @if ($item['carry_origen_periodo'])
                                                    <span class="text-xs text-amber-600">· desde {{ $item['carry_origen_periodo'] }}</span>
                                                @endif
                                                @if ($item['descripcion'])
                                                    <span class="block max-w-md truncate text-xs text-gray-500">{{ $item['descripcion'] }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-filament::section>
            @endforeach
        </div>
    @else
        <p class="text-gray-500">Selecciona una fecha para calcular el planificador.</p>
    @endif
</x-filament-panels::page>
