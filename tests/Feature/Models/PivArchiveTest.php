<?php

declare(strict_types=1);

use App\Models\LvPivArchived;
use App\Models\Piv;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('isArchived returns false when no archive row', function () {
    $piv = Piv::factory()->create(['piv_id' => 99001]);
    expect($piv->isArchived())->toBeFalse();
});

it('isArchived returns true when archive row exists', function () {
    $piv = Piv::factory()->create(['piv_id' => 99002]);
    LvPivArchived::create(['piv_id' => 99002, 'archived_at' => now()]);
    expect($piv->fresh()->isArchived())->toBeTrue();
});

it('scope notArchived excludes archived', function () {
    Piv::factory()->create(['piv_id' => 99003]);
    Piv::factory()->create(['piv_id' => 99004]);
    LvPivArchived::create(['piv_id' => 99004, 'archived_at' => now()]);

    $ids = Piv::notArchived()->pluck('piv_id')->all();
    expect($ids)->toContain(99003)->not->toContain(99004);
});

it('scope onlyArchived returns only archived', function () {
    Piv::factory()->create(['piv_id' => 99005]);
    Piv::factory()->create(['piv_id' => 99006]);
    LvPivArchived::create(['piv_id' => 99006, 'archived_at' => now()]);

    $ids = Piv::onlyArchived()->pluck('piv_id')->all();
    expect($ids)->toContain(99006)->not->toContain(99005);
});

it('uniq_piv_archived prevents double archive', function () {
    Piv::factory()->create(['piv_id' => 99007]);
    LvPivArchived::create(['piv_id' => 99007, 'archived_at' => now()]);

    expect(fn () => LvPivArchived::create(['piv_id' => 99007, 'archived_at' => now()]))
        ->toThrow(QueryException::class);
});
