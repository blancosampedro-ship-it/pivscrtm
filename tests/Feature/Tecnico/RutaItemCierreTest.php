<?php

declare(strict_types=1);

use App\Models\LvAveriaIcca;
use App\Models\LvRevisionPendiente;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\LvRutaDiaItemImagen;
use App\Models\Piv;
use App\Models\Revision;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function rutaItemCierreUser(int $tecnicoId = 78301): User
{
    Tecnico::factory()->create([
        'tecnico_id' => $tecnicoId,
        'status' => 1,
        'email' => 'cierre'.$tecnicoId.'@winfin.local',
    ]);

    return User::factory()->tecnico()->create([
        'legacy_id' => $tecnicoId,
        'email' => 'cierre'.$tecnicoId.'@winfin.local',
    ]);
}

function rutaItemCierreCorrectivo(int $tecnicoId = 78301, array $attributes = []): LvRutaDiaItem
{
    $ruta = LvRutaDia::factory()->create(['tecnico_id' => $tecnicoId, 'fecha' => now('Europe/Madrid')->toDateString()]);
    $averiaIcca = LvAveriaIcca::factory()->create(['piv_id' => Piv::factory()->create(['parada_cod' => 'CIERRE-01'])->piv_id]);

    return LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'tipo_item' => LvRutaDiaItem::TIPO_CORRECTIVO,
        'lv_averia_icca_id' => $averiaIcca->id,
        'lv_revision_pendiente_id' => null,
        'status' => $attributes['status'] ?? LvRutaDiaItem::STATUS_PENDIENTE,
    ]);
}

function rutaItemCierrePreventivo(int $tecnicoId = 78301, string $tipo = LvRutaDiaItem::TIPO_PREVENTIVO): LvRutaDiaItem
{
    $ruta = LvRutaDia::factory()->create(['tecnico_id' => $tecnicoId, 'fecha' => now('Europe/Madrid')->toDateString()]);
    $revisionPendiente = LvRevisionPendiente::factory()->create(['piv_id' => Piv::factory()->create(['parada_cod' => 'REV-01'])->piv_id]);

    return LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'tipo_item' => $tipo,
        'lv_averia_icca_id' => null,
        'lv_revision_pendiente_id' => $revisionPendiente->id,
    ]);
}

it('tecnico cierra correctivo ok desde Volt', function (): void {
    $user = rutaItemCierreUser();
    $item = rutaItemCierreCorrectivo();

    $this->actingAs($user);

    Volt::test('tecnico.ruta-item-cierre', ['itemId' => $item->id])
        ->set('notas', 'Reinicio correcto')
        ->call('cerrar')
        ->assertHasNoErrors()
        ->assertRedirect(route('tecnico.dashboard'));

    expect($item->fresh()->status)->toBe(LvRutaDiaItem::STATUS_CERRADO);
    expect($item->fresh()->notas_tecnico)->toBe('Reinicio correcto');
    expect($item->fresh()->cerrado_at)->not->toBeNull();
});

it('correctivo no_resuelto requiere causa', function (): void {
    $user = rutaItemCierreUser(78302);
    $item = rutaItemCierreCorrectivo(78302);

    $this->actingAs($user);

    Volt::test('tecnico.ruta-item-cierre', ['itemId' => $item->id])
        ->set('status', LvRutaDiaItem::STATUS_NO_RESUELTO)
        ->call('cerrar')
        ->assertHasErrors(['causaCategoria', 'causa_no_resolucion']);
});

it('tecnico cierra preventivo y crea revision legacy', function (): void {
    $user = rutaItemCierreUser(78303);
    $item = rutaItemCierrePreventivo(78303);

    $this->actingAs($user);

    Volt::test('tecnico.ruta-item-cierre', ['itemId' => $item->id])
        ->call('setChecklistItem', 'audio', 'KO')
        ->set('notas', 'Audio revisado')
        ->call('cerrar')
        ->assertHasNoErrors()
        ->assertRedirect(route('tecnico.dashboard'));

    expect(Revision::query()->count())->toBe(1);
    expect(Revision::query()->first()?->audio)->toBe('KO');
});

it('tecnico cierra carry over y crea revision legacy', function (): void {
    $user = rutaItemCierreUser(78304);
    $item = rutaItemCierrePreventivo(78304, LvRutaDiaItem::TIPO_CARRY_OVER);

    $this->actingAs($user);

    Volt::test('tecnico.ruta-item-cierre', ['itemId' => $item->id])
        ->call('cerrar')
        ->assertHasNoErrors()
        ->assertRedirect(route('tecnico.dashboard'));

    expect(Revision::query()->count())->toBe(1);
});

it('acceso a item de otra ruta retorna 403', function (): void {
    $user = rutaItemCierreUser(78305);
    rutaItemCierreUser(78306);
    $item = rutaItemCierreCorrectivo(78306);

    $this->actingAs($user)
        ->get(route('tecnico.ruta-item.cierre', ['itemId' => $item->id]))
        ->assertForbidden();
});

it('submit con foto persiste imagen de item', function (): void {
    Storage::fake('public');
    $user = rutaItemCierreUser(78307);
    $item = rutaItemCierreCorrectivo(78307);

    $this->actingAs($user);

    Volt::test('tecnico.ruta-item-cierre', ['itemId' => $item->id])
        ->set('fotos', [UploadedFile::fake()->image('panel.jpg')])
        ->call('cerrar')
        ->assertHasNoErrors();

    expect(LvRutaDiaItemImagen::where('ruta_dia_item_id', $item->id)->count())->toBe(1);
});

it('item ya cerrado redirige dashboard con flash error', function (): void {
    $user = rutaItemCierreUser(78308);
    $item = rutaItemCierreCorrectivo(78308, ['status' => LvRutaDiaItem::STATUS_CERRADO]);

    $this->actingAs($user);

    Volt::test('tecnico.ruta-item-cierre', ['itemId' => $item->id])
        ->assertRedirect(route('tecnico.dashboard'));
});
