<?php

declare(strict_types=1);

use App\Models\LvAveriaIcca;
use App\Models\Piv;
use App\Models\User;
use App\Services\AveriaIccaImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function sgipCsv(array $rows, string $name = 'SGIP_winfin.csv'): UploadedFile
{
    $content = "Id,Categoría,Resumen,Estado,Descripción,NOTAS,Asignada a\n";

    foreach ($rows as $row) {
        $content .= implode(',', array_map(fn (?string $value): string => '"'.str_replace('"', '""', (string) $value).'"', $row))."\n";
    }

    return UploadedFile::fake()->createWithContent($name, $content);
}

it('lv_averia_icca table exists with expected columns', function (): void {
    expect(Schema::hasTable('lv_averia_icca'))->toBeTrue();
    expect(Schema::hasColumns('lv_averia_icca', [
        'id', 'sgip_id', 'panel_id_sgip', 'piv_id', 'categoria', 'descripcion', 'notas',
        'estado_externo', 'asignada_a', 'activa', 'fecha_import', 'archivo_origen',
        'imported_by_user_id', 'marked_inactive_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('preview parses csv and reports create update inactive and panel issues', function (): void {
    Piv::factory()->create(['piv_id' => 10, 'parada_cod' => '07022']);
    Piv::factory()->create(['piv_id' => 11, 'parada_cod' => '18484A']);
    Piv::factory()->create(['piv_id' => 12, 'parada_cod' => '18484B']);
    LvAveriaIcca::factory()->create(['sgip_id' => '0028078', 'activa' => true]);
    LvAveriaIcca::factory()->create(['sgip_id' => '0099999', 'activa' => true]);

    $preview = app(AveriaIccaImportService::class)->preview(sgipCsv([
        ['0028078', LvAveriaIcca::CAT_COMUNICACION, 'PANEL 07022', 'asignada', 'Sin conexión', 'Nota', 'SGIP_winfin'],
        ['0028079', LvAveriaIcca::CAT_APAGADO, 'PANEL 18484', 'asignada', 'Apagado', 'Nota', 'SGIP_winfin'],
        ['0028079', LvAveriaIcca::CAT_APAGADO, 'PANEL 99999', 'asignada', 'Duplicado', 'Nota', 'SGIP_winfin'],
    ]));

    expect($preview['rows_parsed'])->toBe(3);
    expect($preview['unique_sgip_ids'])->toBe(2);
    expect($preview['would_update'])->toBe(1);
    expect($preview['would_create'])->toBe(1);
    expect($preview['would_mark_inactive'])->toBe(1);
    expect($preview['duplicate_sgip_ids'])->toBe(['0028079']);
    expect($preview['unmatched_panels'])->toBe(['PANEL 99999']);
});

it('missing required csv headers fail fast', function (): void {
    $csv = UploadedFile::fake()->createWithContent('bad.csv', "Id,Categoría,Resumen\n0028078,Panel apagado,PANEL 07022\n");

    app(AveriaIccaImportService::class)->preview($csv);
})->throws(RuntimeException::class, 'CSV missing columns');

it('import creates rows and resolves exact parada cod', function (): void {
    $admin = User::factory()->admin()->create();
    $piv = Piv::factory()->create(['parada_cod' => '07022']);

    $result = app(AveriaIccaImportService::class)->import(sgipCsv([
        ['0028078', LvAveriaIcca::CAT_COMUNICACION, 'PANEL 07022', 'asignada', 'Sin conexión', 'CAU_ICCA 01/05', 'SGIP_winfin'],
    ], 'sgip_exact.csv'), $admin);

    expect($result['created'])->toBe(1);
    expect($result['updated'])->toBe(0);

    $this->assertDatabaseHas('lv_averia_icca', [
        'sgip_id' => '0028078',
        'piv_id' => $piv->piv_id,
        'activa' => true,
        'archivo_origen' => 'sgip_exact.csv',
        'imported_by_user_id' => $admin->id,
    ]);
});

it('import updates existing rows and marks missing active rows inactive', function (): void {
    $admin = User::factory()->admin()->create();
    $existing = LvAveriaIcca::factory()->create([
        'sgip_id' => '0028078',
        'descripcion' => 'Vieja',
        'activa' => true,
    ]);
    $missing = LvAveriaIcca::factory()->create(['sgip_id' => '0029999', 'activa' => true]);

    $result = app(AveriaIccaImportService::class)->import(sgipCsv([
        ['0028078', LvAveriaIcca::CAT_AUDIO, 'PANEL 07022', 'asignada', 'Nueva', 'Nota nueva', 'SGIP_winfin'],
    ]), $admin);

    expect($result['created'])->toBe(0);
    expect($result['updated'])->toBe(1);
    expect($result['marked_inactive'])->toBe(1);
    expect($existing->refresh()->descripcion)->toBe('Nueva');
    expect($existing->activa)->toBeTrue();
    expect($missing->refresh()->activa)->toBeFalse();
    expect($missing->marked_inactive_at)->not->toBeNull();
});

it('numeric cast fallback matches padded or dirty parada cod', function (): void {
    $admin = User::factory()->admin()->create();
    $piv = Piv::factory()->create(['parada_cod' => "07022\t\t"]);

    app(AveriaIccaImportService::class)->import(sgipCsv([
        ['0028078', LvAveriaIcca::CAT_TIEMPOS, 'PANEL 07022', 'asignada', 'Tiempos', '', 'SGIP_winfin'],
    ]), $admin);

    expect(LvAveriaIcca::where('sgip_id', '0028078')->value('piv_id'))->toBe($piv->piv_id);
});

it('ambiguous numeric cast leaves piv null', function (): void {
    $admin = User::factory()->admin()->create();
    Piv::factory()->create(['parada_cod' => '18484A']);
    Piv::factory()->create(['parada_cod' => '18484B']);

    app(AveriaIccaImportService::class)->import(sgipCsv([
        ['0028078', LvAveriaIcca::CAT_APAGADO, 'PANEL 18484', 'asignada', 'Ambiguo', '', 'SGIP_winfin'],
    ]), $admin);

    expect(LvAveriaIcca::where('sgip_id', '0028078')->value('piv_id'))->toBeNull();
});

it('unmatched panel leaves piv null', function (): void {
    $admin = User::factory()->admin()->create();

    app(AveriaIccaImportService::class)->import(sgipCsv([
        ['0028078', LvAveriaIcca::CAT_APAGADO, 'PANEL 99999', 'asignada', 'Sin match', '', 'SGIP_winfin'],
    ]), $admin);

    expect(LvAveriaIcca::where('sgip_id', '0028078')->value('piv_id'))->toBeNull();
});

it('unknown categoria is normalized to otras', function (): void {
    $admin = User::factory()->admin()->create();

    app(AveriaIccaImportService::class)->import(sgipCsv([
        ['0028078', 'Cable roto', 'PANEL 99999', 'asignada', 'Texto', '', 'SGIP_winfin'],
    ]), $admin);

    expect(LvAveriaIcca::where('sgip_id', '0028078')->value('categoria'))->toBe(LvAveriaIcca::CAT_OTRAS);
});

it('import is idempotent for the same snapshot', function (): void {
    $admin = User::factory()->admin()->create();
    $service = app(AveriaIccaImportService::class);
    $csv = sgipCsv([
        ['0028078', LvAveriaIcca::CAT_COMUNICACION, 'PANEL 07022', 'asignada', 'Sin conexión', '', 'SGIP_winfin'],
    ]);

    $service->import($csv, $admin);
    $result = $service->import($csv, $admin);

    expect($result['created'])->toBe(0);
    expect($result['updated'])->toBe(1);
    expect(LvAveriaIcca::where('sgip_id', '0028078')->count())->toBe(1);
});
