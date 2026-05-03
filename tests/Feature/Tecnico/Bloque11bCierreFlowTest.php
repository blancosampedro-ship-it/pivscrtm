<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Correctivo;
use App\Models\LvCorrectivoImagen;
use App\Models\Piv;
use App\Models\Revision;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function bloque11bTecnicoUser(int $tecnicoId = 99050): User
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

function bloque11bAsignacion(array $attributes = []): Asignacion
{
    $asignacionId = $attributes['asignacion_id'] ?? 99050;
    $piv = Piv::factory()->create([
        'piv_id' => $attributes['piv_id'] ?? $asignacionId,
        'parada_cod' => $attributes['parada_cod'] ?? 'SMOKE-'.$asignacionId,
        'direccion' => $attributes['direccion'] ?? 'Calle Técnica '.$asignacionId,
    ]);
    $averia = Averia::factory()->create([
        'averia_id' => $attributes['averia_id'] ?? $asignacionId,
        'piv_id' => $piv->piv_id,
        'notas' => $attributes['averia_notas'] ?? 'Notas operador '.$asignacionId,
    ]);

    return Asignacion::factory()->create([
        'asignacion_id' => $asignacionId,
        'averia_id' => $averia->averia_id,
        'tecnico_id' => $attributes['tecnico_id'] ?? 99050,
        'tipo' => $attributes['tipo'] ?? Asignacion::TIPO_CORRECTIVO,
        'status' => $attributes['status'] ?? 1,
    ]);
}

it('tecnico_can_view_their_own_open_asignacion_cierre_page', function (): void {
    $user = bloque11bTecnicoUser();
    $asignacion = bloque11bAsignacion(['asignacion_id' => 99051, 'parada_cod' => 'OWN-OPEN']);

    $response = $this->actingAs($user)->get(route('tecnico.asignacion.cierre', $asignacion));

    $response->assertOk();
    $response->assertSeeText('Cerrar avería real');
    $response->assertSeeText('OWN-OPEN');
    $response->assertSeeText('Diagnóstico');
});

it('tecnico_cannot_view_other_tecnico_asignacion', function (): void {
    $user = bloque11bTecnicoUser(99052);
    bloque11bTecnicoUser(99053);
    $asignacion = bloque11bAsignacion([
        'asignacion_id' => 99052,
        'piv_id' => 99052,
        'averia_id' => 99052,
        'tecnico_id' => 99053,
    ]);

    $this->actingAs($user)
        ->get(route('tecnico.asignacion.cierre', $asignacion))
        ->assertForbidden();
});

it('tecnico_cannot_view_already_closed_asignacion', function (): void {
    $user = bloque11bTecnicoUser(99054);
    $asignacion = bloque11bAsignacion([
        'asignacion_id' => 99054,
        'piv_id' => 99054,
        'averia_id' => 99054,
        'tecnico_id' => 99054,
        'status' => 2,
    ]);

    $this->actingAs($user)
        ->get(route('tecnico.asignacion.cierre', $asignacion))
        ->assertStatus(410);
});

it('tecnico_can_submit_correctivo_cierre_creates_records_and_redirects_with_flash', function (): void {
    Storage::fake('public');
    $user = bloque11bTecnicoUser(99055);
    $asignacion = bloque11bAsignacion([
        'asignacion_id' => 99055,
        'piv_id' => 99055,
        'averia_id' => 99055,
        'tecnico_id' => 99055,
    ]);

    $this->actingAs($user);

    Volt::test('tecnico.cierre', ['asignacion' => $asignacion])
        ->set('diagnostico', 'Smoke 11b - fallo en alimentación')
        ->set('recambios', 'Sustituida fuente')
        ->set('estado_final', 'OK')
        ->set('tiempo', '1.5')
        ->set('fotos', [UploadedFile::fake()->image('foto.jpg')])
        ->call('cerrar')
        ->assertHasNoErrors()
        ->assertRedirect(route('tecnico.dashboard'));

    $correctivo = Correctivo::where('asignacion_id', $asignacion->asignacion_id)->first();
    $imagen = LvCorrectivoImagen::where('correctivo_id', $correctivo->correctivo_id)->first();

    expect($correctivo)->not->toBeNull();
    expect($correctivo->tecnico_id)->toBe(99055);
    expect($correctivo->diagnostico)->toBe('Smoke 11b - fallo en alimentación');
    expect($correctivo->recambios)->toBe('Sustituida fuente');
    expect($asignacion->fresh()->status)->toBe(2);
    expect($imagen)->not->toBeNull();
    expect($imagen->url)->toStartWith('piv-images/correctivo/');
    Storage::disk('public')->assertExists($imagen->url);
    expect(session('cierre_ok'))->toBe('Asignación #'.$asignacion->asignacion_id.' cerrada.');
});

it('tecnico_can_submit_revision_cierre_creates_revision_and_redirects', function (): void {
    $user = bloque11bTecnicoUser(99056);
    $asignacion = bloque11bAsignacion([
        'asignacion_id' => 99056,
        'piv_id' => 99056,
        'averia_id' => 99056,
        'tecnico_id' => 99056,
        'tipo' => Asignacion::TIPO_REVISION,
    ]);

    $this->actingAs($user);

    Volt::test('tecnico.cierre', ['asignacion' => $asignacion])
        ->set('fecha', '2026-05-03')
        ->set('ruta', 'Ruta smoke')
        ->set('fecha_hora', 'OK')
        ->set('aspecto', 'OK')
        ->set('funcionamiento', 'OK')
        ->set('actuacion', 'OK')
        ->set('audio', 'OK')
        ->set('lineas', 'OK')
        ->set('precision_paso', 'OK')
        ->set('notas', '')
        ->call('cerrar')
        ->assertHasNoErrors()
        ->assertRedirect(route('tecnico.dashboard'));

    $revision = Revision::where('asignacion_id', $asignacion->asignacion_id)->first();

    expect($revision)->not->toBeNull();
    expect($revision->tecnico_id)->toBe(99056);
    expect($revision->ruta)->toBe('Ruta smoke');
    expect($revision->notas)->toBeNull();
    expect(Correctivo::where('asignacion_id', $asignacion->asignacion_id)->exists())->toBeFalse();
    expect($asignacion->fresh()->status)->toBe(2);
});

it('tecnico_idempotent_cierre_shows_error_on_second_submit', function (): void {
    $user = bloque11bTecnicoUser(99057);
    $asignacion = bloque11bAsignacion([
        'asignacion_id' => 99057,
        'piv_id' => 99057,
        'averia_id' => 99057,
        'tecnico_id' => 99057,
    ]);
    Correctivo::factory()->create(['asignacion_id' => $asignacion->asignacion_id]);

    $this->actingAs($user);

    Volt::test('tecnico.cierre', ['asignacion' => $asignacion])
        ->set('diagnostico', 'Segundo cierre')
        ->set('recambios', 'No debe crear')
        ->set('estado_final', 'OK')
        ->call('cerrar')
        ->assertHasErrors(['cerrar']);

    expect(Correctivo::where('asignacion_id', $asignacion->asignacion_id)->count())->toBe(1);
    expect($asignacion->fresh()->status)->toBe(1);
});