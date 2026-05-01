<?php

declare(strict_types=1);

use App\Filament\Resources\PivResource\Pages\ListPivs;
use App\Models\LvPivArchived;
use App\Models\Piv;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('archive_action_creates_lv_piv_archived_row', function () {
    $piv = Piv::factory()->create(['piv_id' => 88001]);

    Livewire::test(ListPivs::class)
        ->callTableAction('archive', $piv->piv_id, data: ['reason' => 'test archive']);

    expect(LvPivArchived::where('piv_id', 88001)->exists())->toBeTrue();
    expect(LvPivArchived::where('piv_id', 88001)->first()->reason)->toBe('test archive');
});

it('unarchive_action_deletes_lv_piv_archived_row', function () {
    $piv = Piv::factory()->create(['piv_id' => 88002]);
    LvPivArchived::create(['piv_id' => 88002, 'archived_at' => now()]);

    Livewire::test(ListPivs::class)
        ->filterTable('archived', true)
        ->callTableAction('unarchive', $piv->piv_id);

    expect(LvPivArchived::where('piv_id', 88002)->exists())->toBeFalse();
});

it('archived_pivs_excluded_from_default_listing', function () {
    $active = Piv::factory()->create(['piv_id' => 88003]);
    $archived = Piv::factory()->create(['piv_id' => 88004]);
    LvPivArchived::create(['piv_id' => 88004, 'archived_at' => now()]);

    Livewire::test(ListPivs::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$archived]);
});

it('filter_archived_shows_only_archived', function () {
    $active = Piv::factory()->create(['piv_id' => 88005]);
    $archived = Piv::factory()->create(['piv_id' => 88006]);
    LvPivArchived::create(['piv_id' => 88006, 'archived_at' => now()]);

    Livewire::test(ListPivs::class)
        ->filterTable('archived', true)
        ->assertCanSeeTableRecords([$archived])
        ->assertCanNotSeeTableRecords([$active]);
});

it('bulk_archive_inserts_multiple_rows', function () {
    $pivs = collect(range(88010, 88014))->map(fn ($id) => Piv::factory()->create(['piv_id' => $id]));

    Livewire::test(ListPivs::class)
        ->callTableBulkAction('archiveSelected', $pivs->pluck('piv_id')->all(), data: ['reason' => 'bulk test']);

    expect(LvPivArchived::whereIn('piv_id', $pivs->pluck('piv_id'))->count())->toBe(5);
});
