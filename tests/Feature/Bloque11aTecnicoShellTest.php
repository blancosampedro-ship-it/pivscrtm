<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

it('tecnico_login_screen_renders_at_tecnico_login', function (): void {
    $response = $this->get(route('tecnico.login'));

    $response->assertOk();
    $response->assertSeeText('Acceso técnico');
});

it('tecnico_can_login_with_legacy_sha1_password', function (): void {
    Tecnico::factory()->create([
        'tecnico_id' => 99001,
        'email' => 'test.tecnico@winfin.local',
        'clave' => sha1('SECRET-pass-123'),
        'status' => 1,
        'nombre_completo' => 'Test Técnico',
    ]);

    $response = Volt::test('tecnico.login')
        ->set('email', 'test.tecnico@winfin.local')
        ->set('password', 'SECRET-pass-123')
        ->call('login');

    $response->assertHasNoErrors();
    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->isTecnico())->toBeTrue();
    expect((int) auth()->user()->legacy_id)->toBe(99001);
});

it('tecnico_dashboard_requires_authentication', function (): void {
    $response = $this->get(route('tecnico.dashboard'));

    $response->assertRedirect(route('tecnico.login'));
});

it('admin_user_cannot_access_tecnico_dashboard', function (): void {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('tecnico.dashboard'));

    $response->assertRedirect(route('tecnico.login'));
});

it('tecnico_dashboard_shows_only_my_open_asignaciones', function (): void {
    Tecnico::factory()->create(['tecnico_id' => 99010, 'status' => 1]);
    Tecnico::factory()->create(['tecnico_id' => 99011, 'status' => 1]);

    $miUser = User::factory()->tecnico()->create([
        'legacy_id' => 99010,
        'email' => 'mi.tecnico@winfin.local',
        'name' => 'Mi Técnico',
    ]);

    $panelCorrectivo = Piv::factory()->create([
        'piv_id' => 99500,
        'parada_cod' => 'VISIBLE-CORR',
        'direccion' => 'Calle Visible Correctivo',
    ]);
    $panelRevision = Piv::factory()->create([
        'piv_id' => 99501,
        'parada_cod' => 'VISIBLE-REV',
        'direccion' => 'Calle Visible Revision',
    ]);
    $panelCerrado = Piv::factory()->create([
        'piv_id' => 99502,
        'parada_cod' => 'HIDDEN-CLOSED',
        'direccion' => 'Calle Cerrada',
    ]);
    $panelOtro = Piv::factory()->create([
        'piv_id' => 99503,
        'parada_cod' => 'HIDDEN-OTHER',
        'direccion' => 'Calle Otro Técnico',
    ]);

    $averiaCorrectivo = Averia::factory()->create(['averia_id' => 99100, 'piv_id' => $panelCorrectivo->piv_id]);
    Asignacion::factory()->correctivo()->create([
        'asignacion_id' => 99100,
        'averia_id' => $averiaCorrectivo->averia_id,
        'tecnico_id' => 99010,
        'status' => 1,
    ]);

    $averiaRevision = Averia::factory()->create(['averia_id' => 99101, 'piv_id' => $panelRevision->piv_id]);
    Asignacion::factory()->revision()->create([
        'asignacion_id' => 99101,
        'averia_id' => $averiaRevision->averia_id,
        'tecnico_id' => 99010,
        'status' => 1,
    ]);

    $averiaCerrada = Averia::factory()->create(['averia_id' => 99102, 'piv_id' => $panelCerrado->piv_id]);
    Asignacion::factory()->correctivo()->create([
        'asignacion_id' => 99102,
        'averia_id' => $averiaCerrada->averia_id,
        'tecnico_id' => 99010,
        'status' => 2,
    ]);

    $averiaOtro = Averia::factory()->create(['averia_id' => 99103, 'piv_id' => $panelOtro->piv_id]);
    Asignacion::factory()->correctivo()->create([
        'asignacion_id' => 99103,
        'averia_id' => $averiaOtro->averia_id,
        'tecnico_id' => 99011,
        'status' => 1,
    ]);

    $response = $this->actingAs($miUser)->get(route('tecnico.dashboard'));

    $response->assertOk();
    $response->assertSeeText('Mis asignaciones abiertas');
    $response->assertSeeText('Avería real');
    $response->assertSeeText('Revisión mensual');
    $response->assertSeeText('VISIBLE-CORR');
    $response->assertSeeText('VISIBLE-REV');
    $response->assertDontSeeText('HIDDEN-CLOSED');
    $response->assertDontSeeText('HIDDEN-OTHER');
    expect(substr_count($response->getContent(), 'data-asignacion-card'))->toBe(2);
});

it('tecnico_logout_returns_to_login', function (): void {
    Tecnico::factory()->create(['tecnico_id' => 99020, 'status' => 1]);
    $user = User::factory()->tecnico()->create([
        'legacy_id' => 99020,
        'email' => 'logout@test.local',
    ]);

    $response = $this->actingAs($user)->post(route('tecnico.logout'));

    $response->assertRedirect(route('tecnico.login'));
    expect(auth()->check())->toBeFalse();
});
