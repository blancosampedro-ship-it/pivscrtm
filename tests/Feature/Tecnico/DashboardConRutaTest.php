<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\LvAveriaIcca;
use App\Models\LvDerivacion;
use App\Models\LvRutaDia;
use App\Models\LvRutaDiaItem;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dashboardRutaTecnicoUser(int $tecnicoId = 78201, int $status = 1): User
{
    Tecnico::factory()->create([
        'tecnico_id' => $tecnicoId,
        'status' => $status,
        'email' => 'ruta'.$tecnicoId.'@winfin.local',
        'nombre_completo' => 'Ruta Técnico '.$tecnicoId,
    ]);

    return User::factory()->tecnico()->create([
        'legacy_id' => $tecnicoId,
        'email' => 'ruta'.$tecnicoId.'@winfin.local',
        'name' => 'Ruta Técnico '.$tecnicoId,
    ]);
}

it('tecnico autenticado con ruta del dia ve cards de items', function (): void {
    $user = dashboardRutaTecnicoUser();
    $ruta = LvRutaDia::factory()->create(['tecnico_id' => 78201, 'fecha' => now('Europe/Madrid')->toDateString()]);
    $piv = Piv::factory()->create(['parada_cod' => 'RUTA-01']);
    $averiaIcca = LvAveriaIcca::factory()->create(['piv_id' => $piv->piv_id, 'categoria' => LvAveriaIcca::CAT_AUDIO]);
    LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'tipo_item' => LvRutaDiaItem::TIPO_CORRECTIVO,
        'lv_averia_icca_id' => $averiaIcca->id,
        'lv_revision_pendiente_id' => null,
    ]);

    $this->actingAs($user)
        ->get(route('tecnico.dashboard'))
        ->assertOk()
        ->assertSeeText('Tu ruta del día')
        ->assertSee('data-ruta-item-card', false)
        ->assertSeeText('RUTA-01')
        ->assertSeeText('Correctivo');
});

it('tecnico sin ruta cae al fallback de asignaciones legacy abiertas', function (): void {
    $user = dashboardRutaTecnicoUser(78202);
    $piv = Piv::factory()->create(['parada_cod' => 'FALLBACK-01']);
    $averia = Averia::factory()->create(['piv_id' => $piv->piv_id]);
    Asignacion::factory()->correctivo()->create([
        'averia_id' => $averia->averia_id,
        'tecnico_id' => 78202,
        'status' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('tecnico.dashboard'))
        ->assertOk()
        ->assertSeeText('Mis asignaciones abiertas')
        ->assertSee('data-asignacion-card', false)
        ->assertSeeText('FALLBACK-01');
});

it('cards muestran stripe lateral color por tipo', function (): void {
    $user = dashboardRutaTecnicoUser(78203);
    $ruta = LvRutaDia::factory()->create(['tecnico_id' => 78203, 'fecha' => now('Europe/Madrid')->toDateString()]);
    $averiaIcca = LvAveriaIcca::factory()->create(['piv_id' => Piv::factory()->create()->piv_id]);
    LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'tipo_item' => LvRutaDiaItem::TIPO_CORRECTIVO,
        'lv_averia_icca_id' => $averiaIcca->id,
        'lv_revision_pendiente_id' => null,
    ]);

    $this->actingAs($user)
        ->get(route('tecnico.dashboard'))
        ->assertOk()
        ->assertSee('bg-red-600', false);
});

it('items cerrados muestran opacity y checkmark', function (): void {
    $user = dashboardRutaTecnicoUser(78204);
    $ruta = LvRutaDia::factory()->create(['tecnico_id' => 78204, 'fecha' => now('Europe/Madrid')->toDateString()]);
    $averiaIcca = LvAveriaIcca::factory()->create(['piv_id' => Piv::factory()->create()->piv_id]);
    LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'tipo_item' => LvRutaDiaItem::TIPO_CORRECTIVO,
        'lv_averia_icca_id' => $averiaIcca->id,
        'lv_revision_pendiente_id' => null,
        'status' => LvRutaDiaItem::STATUS_CERRADO,
    ]);

    $this->actingAs($user)
        ->get(route('tecnico.dashboard'))
        ->assertOk()
        ->assertSee('opacity-60', false)
        ->assertSee('✓', false);
});

it('tap card abierta enlaza al cierre', function (): void {
    $user = dashboardRutaTecnicoUser(78205);
    $ruta = LvRutaDia::factory()->create(['tecnico_id' => 78205, 'fecha' => now('Europe/Madrid')->toDateString()]);
    $averiaIcca = LvAveriaIcca::factory()->create(['piv_id' => Piv::factory()->create()->piv_id]);
    $item = LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'tipo_item' => LvRutaDiaItem::TIPO_CORRECTIVO,
        'lv_averia_icca_id' => $averiaIcca->id,
        'lv_revision_pendiente_id' => null,
    ]);

    $this->actingAs($user)
        ->get(route('tecnico.dashboard'))
        ->assertOk()
        ->assertSee(route('tecnico.ruta-item.cierre', ['itemId' => $item->id]), false);
});

it('tecnico inactivo no puede acceder', function (): void {
    $user = dashboardRutaTecnicoUser(78206, 0);

    $this->actingAs($user)
        ->get(route('tecnico.dashboard'))
        ->assertRedirect(route('tecnico.login'));
});

it('dashboard tecnico no muestra items derivados', function (): void {
    $user = dashboardRutaTecnicoUser(78207);
    $ruta = LvRutaDia::factory()->create(['tecnico_id' => 78207, 'fecha' => now('Europe/Madrid')->toDateString()]);
    $pivPendiente = Piv::factory()->create(['parada_cod' => 'VISIBLE-01']);
    $pivDerivado = Piv::factory()->create(['parada_cod' => 'DERIVADO-01']);
    $averiaPendiente = LvAveriaIcca::factory()->create(['piv_id' => $pivPendiente->piv_id]);
    $averiaDerivada = LvAveriaIcca::factory()->create(['piv_id' => $pivDerivado->piv_id]);

    LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'lv_averia_icca_id' => $averiaPendiente->id,
        'status' => LvRutaDiaItem::STATUS_PENDIENTE,
    ]);
    LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'lv_averia_icca_id' => $averiaDerivada->id,
        'status' => LvRutaDiaItem::STATUS_DERIVADO,
    ]);

    $this->actingAs($user)
        ->get(route('tecnico.dashboard'))
        ->assertOk()
        ->assertSeeText('VISIBLE-01')
        ->assertDontSeeText('DERIVADO-01');
});

it('dashboard tecnico muestra pendiente aunque tenga derivacion cerrada previa', function (): void {
    $user = dashboardRutaTecnicoUser(78208);
    $ruta = LvRutaDia::factory()->create(['tecnico_id' => 78208, 'fecha' => now('Europe/Madrid')->toDateString()]);
    $piv = Piv::factory()->create(['parada_cod' => 'DEVUELTO-01']);
    $averia = LvAveriaIcca::factory()->create(['piv_id' => $piv->piv_id]);
    $item = LvRutaDiaItem::factory()->create([
        'ruta_dia_id' => $ruta->id,
        'lv_averia_icca_id' => $averia->id,
        'status' => LvRutaDiaItem::STATUS_PENDIENTE,
    ]);
    LvDerivacion::factory()->cerrada(LvDerivacion::STATUS_DEVUELTO_A_RUTA)->create(['lv_ruta_dia_item_id' => $item->id]);

    $this->actingAs($user)
        ->get(route('tecnico.dashboard'))
        ->assertOk()
        ->assertSeeText('DEVUELTO-01');
});
