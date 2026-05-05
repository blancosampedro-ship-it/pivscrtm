<?php

declare(strict_types=1);

use App\Filament\Pages\ImportarSgip;
use App\Filament\Resources\LvAveriaIccaResource;
use App\Filament\Resources\LvAveriaIccaResource\Pages\ListLvAveriaIccas;
use App\Models\LvAveriaIcca;
use App\Models\Modulo;
use App\Models\Piv;
use App\Models\PivRuta;
use App\Models\PivRutaMunicipio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('admin can view averia icca resource and slug is explicit', function (): void {
    $row = LvAveriaIcca::factory()->create(['sgip_id' => '0028078']);

    $this->get(LvAveriaIccaResource::getUrl('index'))
        ->assertOk()
        ->assertSee('0028078');

    expect(LvAveriaIccaResource::getSlug())->toBe('averias-icca');
    expect(LvAveriaIccaResource::getNavigationLabel())->toBe('Averías ICCA');

    Livewire::test(ListLvAveriaIccas::class)
        ->assertCanSeeTableRecords([$row]);
});

it('non admin cannot view averia icca resource or importer page', function (): void {
    $this->actingAs(User::factory()->tecnico()->create());

    $this->get(LvAveriaIccaResource::getUrl('index'))->assertForbidden();
    $this->get(ImportarSgip::getUrl())->assertForbidden();
});

it('resource filters by active and category', function (): void {
    $activeCommunication = LvAveriaIcca::factory()->create([
        'categoria' => LvAveriaIcca::CAT_COMUNICACION,
        'activa' => true,
    ]);
    $inactiveAudio = LvAveriaIcca::factory()->inactiva()->create([
        'categoria' => LvAveriaIcca::CAT_AUDIO,
    ]);

    Livewire::test(ListLvAveriaIccas::class)
        ->filterTable('activa', true)
        ->filterTable('categoria', LvAveriaIcca::CAT_COMUNICACION)
        ->assertCanSeeTableRecords([$activeCommunication])
        ->assertCanNotSeeTableRecords([$inactiveAudio]);
});

it('resource filters by ruta and sin ruta', function (): void {
    $municipio = Modulo::factory()->municipio('Alcalá de Henares')->create();
    $ruta = PivRuta::factory()->create(['nombre' => 'Azul']);
    PivRutaMunicipio::factory()->create([
        'ruta_id' => $ruta->id,
        'municipio_modulo_id' => $municipio->modulo_id,
    ]);
    $pivRuta = Piv::factory()->create(['municipio' => (string) $municipio->modulo_id]);
    $pivSinRuta = Piv::factory()->create(['municipio' => '999999']);
    $rowRuta = LvAveriaIcca::factory()->create(['piv_id' => $pivRuta->piv_id]);
    $rowSinRuta = LvAveriaIcca::factory()->create(['piv_id' => $pivSinRuta->piv_id]);

    Livewire::test(ListLvAveriaIccas::class)
        ->filterTable('ruta', $ruta->id)
        ->assertCanSeeTableRecords([$rowRuta])
        ->assertCanNotSeeTableRecords([$rowSinRuta]);

    Livewire::test(ListLvAveriaIccas::class)
        ->filterTable('ruta', '__none')
        ->assertCanSeeTableRecords([$rowSinRuta])
        ->assertCanNotSeeTableRecords([$rowRuta]);
});

it('importer page renders for admin', function (): void {
    $this->get(ImportarSgip::getUrl())->assertOk()->assertSee('CSV SGIP exportado');
});
