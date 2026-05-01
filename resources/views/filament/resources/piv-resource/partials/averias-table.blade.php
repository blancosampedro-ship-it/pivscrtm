@php
    /**
     * @var \App\Models\Piv $piv
     */
    $averias = $piv->averias()
        ->with([
            'tecnico:tecnico_id,nombre_completo',
            'operador:operador_id,razon_social',
            'asignacion:asignacion_id,averia_id,tipo,status,hora_inicial,hora_final',
        ])
        ->orderByDesc('fecha')
        ->limit(50)
        ->get();

    $tipoMap = [
        1 => ['label' => 'Correctivo', 'classes' => 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200'],
        2 => ['label' => 'Revisión', 'classes' => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200'],
    ];
    $sinAsignacion = ['label' => 'Sin asignar', 'classes' => 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200'];
@endphp

<section class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-slate-950/5 dark:bg-slate-900 dark:ring-white/10">
    <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-6 py-4 dark:border-white/10">
        <div>
            <h3 class="text-base font-semibold text-slate-950 dark:text-white">
                Histórico de averías
            </h3>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                Últimas {{ $averias->count() }} de {{ $piv->averias()->count() }} averías registradas en este panel.
            </p>
        </div>
    </header>

    @if ($averias->isEmpty())
        <div class="px-6 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
            Este panel no tiene averías registradas.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 dark:border-white/10 dark:bg-slate-800/50">
                    <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        <th scope="col" class="px-6 py-2.5 font-medium">ID</th>
                        <th scope="col" class="px-6 py-2.5 font-medium">Fecha</th>
                        <th scope="col" class="px-6 py-2.5 font-medium">Tipo</th>
                        <th scope="col" class="px-6 py-2.5 font-medium">Horario</th>
                        <th scope="col" class="px-6 py-2.5 font-medium">Técnico</th>
                        <th scope="col" class="px-6 py-2.5 font-medium">Operador reporta</th>
                        <th scope="col" class="px-6 py-2.5 font-medium">Status</th>
                        <th scope="col" class="px-6 py-2.5 font-medium">Notas</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @foreach ($averias as $averia)
                        @php
                            $tipo = (int) ($averia->asignacion?->tipo ?? 0);
                            $tipoBadge = $tipoMap[$tipo] ?? $sinAsignacion;
                            $horario = $averia->asignacion?->hora_inicial !== null && $averia->asignacion?->hora_final !== null
                                ? sprintf('%02d–%02d h', $averia->asignacion->hora_inicial, $averia->asignacion->hora_final)
                                : '—';
                            $statusBadge = match ((int) $averia->status) {
                                1 => ['label' => 'Abierta', 'classes' => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200'],
                                2 => ['label' => 'Cerrada', 'classes' => 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200'],
                                default => ['label' => (string) $averia->status, 'classes' => 'bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200'],
                            };
                        @endphp
                        <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                            <td class="whitespace-nowrap px-6 py-2.5 font-mono text-xs text-slate-500 dark:text-slate-400">
                                #{{ str_pad((string) $averia->averia_id, 5, '0', STR_PAD_LEFT) }}
                            </td>
                            <td class="whitespace-nowrap px-6 py-2.5 font-mono text-xs text-slate-700 dark:text-slate-300">
                                {{ optional($averia->fecha)->format('d M Y · H:i') ?? '—' }}
                            </td>
                            <td class="whitespace-nowrap px-6 py-2.5">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium {{ $tipoBadge['classes'] }}">
                                    {{ $tipoBadge['label'] }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-6 py-2.5 font-mono text-xs text-slate-500 dark:text-slate-400">
                                {{ $horario }}
                            </td>
                            <td class="whitespace-nowrap px-6 py-2.5 text-slate-700 dark:text-slate-200">
                                {{ \Illuminate\Support\Str::limit((string) ($averia->tecnico?->nombre_completo ?? '—'), 25) }}
                            </td>
                            <td class="whitespace-nowrap px-6 py-2.5 text-slate-700 dark:text-slate-200">
                                {{ \Illuminate\Support\Str::limit((string) ($averia->operador?->razon_social ?? '—'), 25) }}
                            </td>
                            <td class="whitespace-nowrap px-6 py-2.5">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium {{ $statusBadge['classes'] }}">
                                    {{ $statusBadge['label'] }}
                                </span>
                            </td>
                            <td class="px-6 py-2.5 text-slate-600 dark:text-slate-400">
                                <div class="max-w-xs truncate" title="{{ $averia->notas }}">
                                    {{ \Illuminate\Support\Str::limit((string) ($averia->notas ?? ''), 60) ?: '—' }}
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
