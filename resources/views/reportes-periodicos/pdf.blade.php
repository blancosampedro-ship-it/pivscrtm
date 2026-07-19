<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de Mantenimiento PIVs Madrid</title>
    <style>
        @page { margin: 2cm; }
        body { color: #1f2937; font-family: DejaVu Sans, sans-serif; font-size: 10.5px; line-height: 1.35; }
        .header { border-bottom: 1px solid #1D3F8C; height: 42px; position: fixed; top: -1.2cm; left: 0; right: 0; }
        .header table, .footer table { width: 100%; border-collapse: collapse; }
        .brand { color: #1D3F8C; font-family: DejaVu Serif, serif; font-size: 15px; font-weight: bold; }
        .doc-title { font-size: 11px; font-weight: bold; text-align: center; }
        .period { color: #4b5563; font-size: 10px; text-align: right; }
        .footer { border-top: 1px solid #d1d5db; bottom: -1.15cm; color: #6b7280; font-size: 8.5px; height: 30px; left: 0; position: fixed; right: 0; }
        .page-break { page-break-after: always; }
        .cover { padding-top: 120px; text-align: center; }
        .cover-logo { color: #1D3F8C; font-family: DejaVu Serif, serif; font-size: 34px; font-weight: bold; margin-bottom: 58px; }
        h1 { color: #111827; font-size: 24px; letter-spacing: 0; margin: 0 0 16px; }
        h2 { border-bottom: 2px solid #1D3F8C; color: #111827; font-size: 16px; margin: 0 0 14px; padding-bottom: 5px; }
        h3 { color: #1D3F8C; font-size: 12px; margin: 18px 0 8px; }
        .subtitle { color: #374151; font-size: 16px; margin-bottom: 46px; }
        .muted { color: #6b7280; }
        .section { margin-bottom: 18px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #1D3F8C; color: white; font-weight: bold; }
        th, td { border: 1px solid #d9e2f3; padding: 5px 6px; vertical-align: top; }
        .kpi-grid { width: 100%; border-collapse: separate; border-spacing: 8px; margin-bottom: 18px; }
        .kpi-grid td { border: 1px solid #d9e2f3; padding: 10px; width: 25%; }
        .kpi-value { color: #1D3F8C; display: block; font-size: 20px; font-weight: bold; }
        .kpi-label { color: #4b5563; display: block; font-size: 9px; margin-top: 3px; }
        .signature { border: 1px solid #9ca3af; height: 120px; margin-top: 36px; padding: 12px; }
        .small { font-size: 9px; }
    </style>
</head>
<body>
    <script type="text/php">
        if (isset($pdf)) {
            $pdf->page_text(477, 810, "Página {PAGE_NUM} de {PAGE_COUNT}", null, 8, [0.42, 0.45, 0.50]);
        }
    </script>

    <div class="header">
        <table>
            <tr>
                <td class="brand">Winfin Sistemas</td>
                <td class="doc-title">Reporte de Mantenimiento PIVs Madrid</td>
                <td class="period">{{ $kpis['periodo']['label'] }}</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        <table>
            <tr>
                <td>Generado por {{ $admin->name }} el {{ $generatedAt->format('Y-m-d H:i') }}</td>
                <td style="text-align: right;">Winfin Sistemas S.L.</td>
            </tr>
        </table>
    </div>

    <div class="cover">
        <div class="cover-logo">Winfin Sistemas</div>
        <h1>REPORTE {{ $tipoLabel }} DE MANTENIMIENTO</h1>
        <div class="subtitle">{{ $kpis['periodo']['label'] }}</div>
        <p>Contratista: Winfin Sistemas S.L.@if(config('services.winfin.cif')) - CIF {{ config('services.winfin.cif') }}@endif</p>
        <p>Cliente: Consorcio Regional de Transportes de Madrid (CRTM)</p>
        <p>Generado: {{ $generatedAt->format('Y-m-d H:i') }}</p>
    </div>

    <div class="page-break"></div>

    <h2>Resumen ejecutivo</h2>
    <table class="kpi-grid">
        <tr>
            <td><span class="kpi-value">{{ $kpis['volumen']['averias_icca_importadas'] }}</span><span class="kpi-label">Averías ICCA importadas</span></td>
            <td><span class="kpi-value">{{ $kpis['volumen']['revisiones_completadas'] }}/{{ $kpis['volumen']['revisiones_planificadas'] }}</span><span class="kpi-label">Revisiones completadas</span></td>
            <td><span class="kpi-value">{{ $kpis['volumen']['items_cerrados_total'] }}</span><span class="kpi-label">Items cerrados</span></td>
            <td><span class="kpi-value">{{ $kpis['derivaciones']['total'] }}</span><span class="kpi-label">Derivaciones</span></td>
        </tr>
        <tr>
            <td><span class="kpi-value">{{ number_format($kpis['resolucion']['pct_revisiones_completadas'], 2, ',', '.') }}%</span><span class="kpi-label">Cumplimiento preventivo</span></td>
            <td><span class="kpi-value">{{ number_format($kpis['resolucion']['pct_items_resueltos_en_ruta'], 2, ',', '.') }}%</span><span class="kpi-label">Resueltos en ruta</span></td>
            <td><span class="kpi-value">{{ number_format($kpis['resolucion']['tiempo_medio_cierre_horas'], 2, ',', '.') }} h</span><span class="kpi-label">Tiempo medio cierre</span></td>
            <td><span class="kpi-value">{{ number_format($kpis['derivaciones']['tiempo_medio_resolucion_dias'], 2, ',', '.') }} d</span><span class="kpi-label">Resolución terceros</span></td>
        </tr>
    </table>

    <table>
        <tr><th>KPI</th><th>Valor</th></tr>
        @foreach($kpis['volumen'] as $label => $value)
            <tr><td>{{ str_replace('_', ' ', ucfirst($label)) }}</td><td>{{ $value }}</td></tr>
        @endforeach
        @foreach($kpis['resolucion'] as $label => $value)
            <tr><td>{{ str_replace('_', ' ', ucfirst($label)) }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>

    <div class="page-break"></div>

    <h2>Detalle averías ICCA</h2>
    <div class="section">
        <table>
            <tr><th>SGIP</th><th>Panel</th><th>Municipio</th><th>Categoría</th><th>Activa</th></tr>
            @forelse(array_slice($kpis['tablas']['averias_icca'], 0, $kpis['periodo']['tipo'] === 'mensual' ? 20 : 50) as $row)
                <tr><td>{{ $row['sgip_id'] }}</td><td>{{ $row['panel'] }}</td><td>{{ $row['municipio'] }}</td><td>{{ $row['categoria'] }}</td><td>{{ $row['activa'] }}</td></tr>
            @empty
                <tr><td colspan="5" class="muted">Sin averías ICCA en el periodo.</td></tr>
            @endforelse
        </table>
    </div>

    <h2>Detalle revisiones preventivas</h2>
    <table>
        <tr><th>PIV</th><th>Parada</th><th>Municipio</th><th>Periodo</th><th>Status</th></tr>
        @forelse($kpis['tablas']['revisiones'] as $row)
            <tr><td>{{ $row['piv_id'] }}</td><td>{{ $row['parada'] }}</td><td>{{ $row['municipio'] }}</td><td>{{ $row['periodo'] }}</td><td>{{ $row['status'] }}</td></tr>
        @empty
            <tr><td colspan="5" class="muted">Sin revisiones preventivas planificadas.</td></tr>
        @endforelse
    </table>

    <div class="page-break"></div>

    <h2>Derivaciones</h2>
    <h3>Por causa</h3>
    <table>
        <tr><th>Causa</th><th>Total</th></tr>
        @foreach($kpis['derivaciones']['por_causa'] as $key => $value)
            <tr><td>{{ $key }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>
    <h3>Por actor responsable</h3>
    <table>
        <tr><th>Actor</th><th>Total</th></tr>
        @foreach($kpis['derivaciones']['por_actor'] as $key => $value)
            <tr><td>{{ $key }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>
    <h3>Por status</h3>
    <table>
        <tr><th>Status</th><th>Total</th></tr>
        @foreach($kpis['derivaciones']['por_status'] as $key => $value)
            <tr><td>{{ $key }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>

    <div class="page-break"></div>

    <h2>Cobertura territorial</h2>
    <h3>Items por ruta WINFIN</h3>
    <table>
        <tr><th>Ruta</th><th>Items</th></tr>
        @foreach($kpis['cobertura_territorial']['items_por_ruta'] as $ruta => $items)
            <tr><td>{{ $ruta }}</td><td>{{ $items }}</td></tr>
        @endforeach
    </table>
    <h3>Top 10 municipios</h3>
    <table>
        <tr><th>Municipio</th><th>Items</th></tr>
        @forelse($kpis['cobertura_territorial']['municipios_top10'] as $row)
            <tr><td>{{ $row['municipio'] }}</td><td>{{ $row['items'] }}</td></tr>
        @empty
            <tr><td colspan="2" class="muted">Sin actividad territorial en el periodo.</td></tr>
        @endforelse
    </table>

    <div class="page-break"></div>

    <h2>Detalle paneles</h2>
    <table>
        <tr><th>PIV</th><th>Parada</th><th>Municipio</th><th>Ruta</th><th>Averías</th><th>Revisiones</th><th>Derivaciones</th></tr>
        @forelse($kpis['detalle_paneles'] as $row)
            <tr><td>{{ $row['piv_id'] ?? '—' }}</td><td>{{ $row['parada_cod'] }}</td><td>{{ $row['municipio'] }}</td><td>{{ $row['ruta'] }}</td><td>{{ $row['averias_icca'] }}</td><td>{{ $row['revisiones_completadas'] }}</td><td>{{ $row['derivaciones'] }}</td></tr>
        @empty
            <tr><td colspan="7" class="muted">Sin paneles con actividad en el periodo.</td></tr>
        @endforelse
    </table>

    <div class="page-break"></div>

    <h2>Página firma</h2>
    <p>Generado por: {{ $admin->name }}</p>
    <p>Fecha generación: {{ $generatedAt->format('Y-m-d H:i') }}</p>
    <div class="signature">
        <p class="muted">Firma y sello</p>
    </div>
</body>
</html>