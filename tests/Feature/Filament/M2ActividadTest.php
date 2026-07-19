<?php

declare(strict_types=1);

use App\Filament\Resources\ActivityResource;
use App\Models\Averia;
use App\Models\LvAveriaIcca;
use App\Models\Piv;
use App\Models\Tecnico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('la tabla de auditoría es lv_activity_log', function (): void {
    expect(config('activitylog.table_name'))->toBe('lv_activity_log');
    expect((new Activity)->getTable())->toBe('lv_activity_log');
});

it('editar una avería registra actividad con el usuario causante', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Piv::factory()->create(['piv_id' => 91001]);
    $averia = Averia::factory()->create(['averia_id' => 91001, 'piv_id' => 91001, 'status' => 1]);

    $averia->update(['status' => 2]);

    $log = Activity::query()->where('log_name', 'averia')->latest('id')->first();
    expect($log)->not->toBeNull();
    expect($log->event)->toBe('updated');
    expect((int) $log->causer_id)->toBe($admin->id);
    expect($log->properties['attributes']['status'])->toBe(2);
});

it('el log de técnicos solo registra atributos de la whitelist (nunca RGPD)', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $tecnico = Tecnico::factory()->create(['tecnico_id' => 91010, 'dni' => '11111111A']);
    Activity::query()->delete(); // limpiar el 'created'

    $tecnico->update(['nombre_completo' => 'Nombre Nuevo', 'dni' => '99999999Z']);

    $log = Activity::query()->where('log_name', 'tecnico')->latest('id')->first();
    expect($log)->not->toBeNull();
    $attrs = (array) $log->properties['attributes'];
    expect($attrs)->toHaveKey('nombre_completo');
    expect($attrs)->not->toHaveKey('dni');
    expect(json_encode($log->properties))->not->toContain('99999999Z');
});

it('borrar una ICCA registra el borrado; sus updates no generan ruido', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $icca = LvAveriaIcca::factory()->create();
    $icca->update(['descripcion' => 'cambio masivo de import']);
    expect(Activity::query()->where('log_name', 'averia_icca')->count())->toBe(0);

    $icca->delete();
    $log = Activity::query()->where('log_name', 'averia_icca')->first();
    expect($log?->event)->toBe('deleted');
});

it('el visor de actividad es accesible para admin y solo lectura', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(ActivityResource::getUrl('index'))->assertOk();
    expect(ActivityResource::canCreate())->toBeFalse();
    expect(ActivityResource::getNavigationGroup())->toBe('Sistema');
});

it('un técnico no puede ver el registro de actividad', function (): void {
    $this->actingAs(User::factory()->tecnico()->create());

    $this->get(ActivityResource::getUrl('index'))->assertForbidden();
});
