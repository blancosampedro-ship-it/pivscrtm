<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the 9 lv_* tables', function () {
    foreach ([
        'lv_users', 'lv_password_reset_tokens', 'lv_sessions',
        'lv_cache', 'lv_cache_locks',
        'lv_jobs', 'lv_job_batches', 'lv_failed_jobs',
        'lv_correctivo_imagen',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Tabla {$table} no existe");
    }
});

it('lv_users has columns according to ADR-0005', function () {
    foreach ([
        'id', 'legacy_kind', 'legacy_id', 'email', 'name',
        'password', 'legacy_password_sha1', 'lv_password_migrated_at',
        'remember_token', 'email_verified_at', 'created_at', 'updated_at',
    ] as $col) {
        expect(Schema::hasColumn('lv_users', $col))->toBeTrue("Columna {$col} falta en lv_users");
    }
});

it('lv_correctivo_imagen has columns according to ADR-0006', function () {
    foreach (['id', 'correctivo_id', 'url', 'posicion', 'created_at', 'updated_at'] as $col) {
        expect(Schema::hasColumn('lv_correctivo_imagen', $col))->toBeTrue();
    }
});

it('creates lv_piv_archived table with correct columns', function () {
    expect(Schema::hasTable('lv_piv_archived'))->toBeTrue();
    foreach (['id', 'piv_id', 'archived_at', 'archived_by_user_id', 'reason', 'created_at', 'updated_at'] as $col) {
        expect(Schema::hasColumn('lv_piv_archived', $col))->toBeTrue("Falta columna {$col}");
    }
});
