<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Correctivo;
use App\Models\LvCorrectivoImagen;
use App\Models\Piv;
use App\Models\PivImagen;
use App\Models\Revision;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function bloque11dTecnicoUser(int $tecnicoId = 99150): User
{
    Tecnico::factory()->create([
        'tecnico_id' => $tecnicoId,
        'status' => 1,
        'email' => 'tecnico'.$tecnicoId.'@winfin.local',
        'nombre_completo' => 'Técnico '.$tecnicoId,
    ]);

    return User::factory()->tecnico()->create([
        'legacy_id' => $tecnicoId,
        'email' => 'tecnico'.$tecnicoId.'@winfin.local',
        'name' => 'Técnico '.$tecnicoId,
    ]);
}

function bloque11dAsignacion(array $attributes = []): Asignacion
{
    $asignacionId = $attributes['asignacion_id'] ?? fake()->unique()->numberBetween(99150, 99999);
    $pivId = $attributes['piv_id'] ?? $asignacionId;

    $piv = Piv::factory()->create([
        'piv_id' => $pivId,
        'parada_cod' => $attributes['parada_cod'] ?? 'FG-'.$asignacionId,
        'direccion' => $attributes['direccion'] ?? 'Calle Campo '.$asignacionId,
    ]);

    $averia = Averia::factory()->create([
        'averia_id' => $attributes['averia_id'] ?? $asignacionId,
        'piv_id' => $piv->piv_id,
        'notas' => $attributes['averia_notas'] ?? 'Notas operador '.$asignacionId,
    ]);

    return Asignacion::factory()->create([
        'asignacion_id' => $asignacionId,
        'averia_id' => $averia->averia_id,
        'tecnico_id' => $attributes['tecnico_id'] ?? 99150,
        'tipo' => $attributes['tipo'] ?? Asignacion::TIPO_CORRECTIVO,
        'status' => $attributes['status'] ?? 1,
    ]);
}

function bloque11dHistoricalCorrectivoForPiv(Piv $piv, string $url, int $id): LvCorrectivoImagen
{
    $averia = Averia::factory()->create([
        'averia_id' => $id,
        'piv_id' => $piv->piv_id,
    ]);

    $asignacion = Asignacion::factory()->create([
        'asignacion_id' => $id,
        'averia_id' => $averia->averia_id,
        'tecnico_id' => 99150,
        'tipo' => Asignacion::TIPO_CORRECTIVO,
        'status' => 2,
    ]);

    $correctivo = Correctivo::factory()->create([
        'correctivo_id' => $id,
        'asignacion_id' => $asignacion->asignacion_id,
        'tecnico_id' => 99150,
    ]);

    return LvCorrectivoImagen::factory()->create([
        'correctivo_id' => $correctivo->correctivo_id,
        'url' => $url,
    ]);
}

it('dashboard_renders_card_with_panel_thumbnail_when_lv_correctivo_imagen_exists', function (): void {
    $user = bloque11dTecnicoUser(99151);
    $asignacion = bloque11dAsignacion([
        'asignacion_id' => 99151,
        'piv_id' => 99151,
        'tecnico_id' => 99151,
    ]);
    bloque11dHistoricalCorrectivoForPiv($asignacion->averia->piv, 'piv-images/correctivo/latest.jpg', 99152);

    $this->actingAs($user)
        ->get(route('tecnico.dashboard'))
        ->assertOk()
        ->assertSee('/storage/piv-images/correctivo/latest.jpg', false)
        ->assertSee('FG-99151');
});

it('dashboard_falls_back_to_legacy_piv_imagen_when_no_correctivo_image', function (): void {
    $user = bloque11dTecnicoUser(99153);
    $asignacion = bloque11dAsignacion([
        'asignacion_id' => 99153,
        'piv_id' => 99153,
        'tecnico_id' => 99153,
    ]);
    PivImagen::factory()->create([
        'piv_id' => $asignacion->averia->piv->piv_id,
        'url' => 'legacy-panel.jpg',
        'posicion' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('tecnico.dashboard'))
        ->assertOk()
        ->assertSee('https://www.winfin.es/images/piv/legacy-panel.jpg', false);
});

it('dashboard_shows_emoji_placeholder_when_panel_has_no_image', function (): void {
    $user = bloque11dTecnicoUser(99154);
    bloque11dAsignacion([
        'asignacion_id' => 99154,
        'piv_id' => 99154,
        'tecnico_id' => 99154,
    ]);

    $this->actingAs($user)
        ->get(route('tecnico.dashboard'))
        ->assertOk()
        ->assertSee('📷');
});

it('correctivo_step_1_advances_after_tap_on_estado_final', function (): void {
    $user = bloque11dTecnicoUser(99155);
    $asignacion = bloque11dAsignacion(['asignacion_id' => 99155, 'tecnico_id' => 99155]);

    $this->actingAs($user);

    Volt::test('tecnico.cierre', ['asignacion' => $asignacion])
        ->call('setEstadoFinal', 'reparado')
        ->assertSet('estadoFinal', 'reparado')
        ->assertSet('step', 2);
});

it('correctivo_step_2_recambio_toggle_adds_and_removes', function (): void {
    $user = bloque11dTecnicoUser(99156);
    $asignacion = bloque11dAsignacion(['asignacion_id' => 99156, 'tecnico_id' => 99156]);

    $this->actingAs($user);

    Volt::test('tecnico.cierre', ['asignacion' => $asignacion])
        ->call('toggleRecambio', 'Cable')
        ->assertSet('recambios', ['Cable'])
        ->call('toggleRecambio', 'Cable')
        ->assertSet('recambios', []);
});

it('correctivo_step_3_set_tiempo_advances_to_4', function (): void {
    $user = bloque11dTecnicoUser(99157);
    $asignacion = bloque11dAsignacion(['asignacion_id' => 99157, 'tecnico_id' => 99157]);

    $this->actingAs($user);

    Volt::test('tecnico.cierre', ['asignacion' => $asignacion])
        ->set('step', 3)
        ->call('setTiempo', '30')
        ->assertSet('tiempoMinutos', '30')
        ->assertSet('step', 4);
});

it('correctivo_full_flow_creates_correctivo_with_normalized_data', function (): void {
    $user = bloque11dTecnicoUser(99158);
    $asignacion = bloque11dAsignacion(['asignacion_id' => 99158, 'tecnico_id' => 99158]);

    $this->actingAs($user);

    Volt::test('tecnico.cierre', ['asignacion' => $asignacion])
        ->call('setEstadoFinal', 'reparado')
        ->call('toggleRecambio', 'Cable')
        ->call('setTiempo', '30')
        ->set('notas', 'Fuente estabilizada')
        ->call('cerrar')
        ->assertHasNoErrors()
        ->assertRedirect(route('tecnico.dashboard'));

    $correctivo = Correctivo::where('asignacion_id', $asignacion->asignacion_id)->firstOrFail();

    expect($correctivo->diagnostico)->toBe('Reparado. Fuente estabilizada');
    expect($correctivo->recambios)->toBe('Cable');
    expect($correctivo->estado_final)->toBe('OK');
    expect($correctivo->tiempo)->toBe('0.5');
    expect($asignacion->fresh()->status)->toBe(2);
});

it('revision_step_1_setRevisionItem_changes_field_value', function (): void {
    $user = bloque11dTecnicoUser(99159);
    $asignacion = bloque11dAsignacion([
        'asignacion_id' => 99159,
        'tecnico_id' => 99159,
        'tipo' => Asignacion::TIPO_REVISION,
    ]);

    $this->actingAs($user);

    Volt::test('tecnico.cierre', ['asignacion' => $asignacion])
        ->call('setRevisionItem', 'audio', 'KO')
        ->assertSet('audio', 'KO');
});

it('revision_full_flow_creates_revision_with_checklist', function (): void {
    $user = bloque11dTecnicoUser(99160);
    $asignacion = bloque11dAsignacion([
        'asignacion_id' => 99160,
        'tecnico_id' => 99160,
        'tipo' => Asignacion::TIPO_REVISION,
    ]);

    $this->actingAs($user);

    Volt::test('tecnico.cierre', ['asignacion' => $asignacion])
        ->call('setRevisionItem', 'aspecto', 'KO')
        ->call('setRevisionItem', 'audio', 'N/A')
        ->set('notas', 'Sin audio por entorno')
        ->call('cerrar')
        ->assertHasNoErrors()
        ->assertRedirect(route('tecnico.dashboard'));

    $revision = Revision::where('asignacion_id', $asignacion->asignacion_id)->firstOrFail();

    expect($revision->aspecto)->toBe('KO');
    expect($revision->audio)->toBe('N/A');
    expect($revision->notas)->toBe('Sin audio por entorno');
    expect(Correctivo::where('asignacion_id', $asignacion->asignacion_id)->exists())->toBeFalse();
});

it('prev_button_decrements_step_but_not_below_1', function (): void {
    $user = bloque11dTecnicoUser(99161);
    $asignacion = bloque11dAsignacion(['asignacion_id' => 99161, 'tecnico_id' => 99161]);

    $this->actingAs($user);

    Volt::test('tecnico.cierre', ['asignacion' => $asignacion])
        ->set('step', 3)
        ->call('prev')
        ->assertSet('step', 2)
        ->call('prev')
        ->call('prev')
        ->assertSet('step', 1);
});

it('tecnico_a_cannot_view_tecnico_b_asignacion', function (): void {
    $user = bloque11dTecnicoUser(99162);
    bloque11dTecnicoUser(99163);
    $asignacion = bloque11dAsignacion([
        'asignacion_id' => 99162,
        'piv_id' => 99162,
        'tecnico_id' => 99163,
    ]);

    $this->actingAs($user)
        ->get(route('tecnico.asignacion.cierre', $asignacion))
        ->assertForbidden();
});

it('session_lifetime_is_90_days', function (): void {
    expect(file_get_contents(base_path('config/session.php')))->toContain("env('SESSION_LIFETIME', 129600)");
    expect(file_get_contents(base_path('.env.example')))->toContain('SESSION_LIFETIME=129600');
    expect(file_get_contents(base_path('.env.example')))->toContain('SESSION_EXPIRE_ON_CLOSE=false');
});

it('piv_current_photo_url_uses_latest_correctivo_image_when_available', function (): void {
    $piv = Piv::factory()->create(['piv_id' => 99164]);
    bloque11dHistoricalCorrectivoForPiv($piv, 'piv-images/correctivo/old.jpg', 99164);
    bloque11dHistoricalCorrectivoForPiv($piv, 'piv-images/correctivo/new.jpg', 99165);

    expect($piv->fresh()->current_photo_url)->toEndWith('/storage/piv-images/correctivo/new.jpg');
});

it('piv_current_photo_url_falls_back_to_legacy_thumbnail_when_no_correctivo', function (): void {
    $piv = Piv::factory()->create(['piv_id' => 99166]);
    PivImagen::factory()->create([
        'piv_id' => $piv->piv_id,
        'url' => 'legacy-fallback.jpg',
        'posicion' => 1,
    ]);

    expect($piv->fresh()->current_photo_url)->toBe('https://www.winfin.es/images/piv/legacy-fallback.jpg');
});

it('piv_current_photo_url_is_null_when_no_images_exist', function (): void {
    $piv = Piv::factory()->create(['piv_id' => 99167]);

    expect($piv->fresh()->current_photo_url)->toBeNull();
});
