<?php

declare(strict_types=1);

it('session config points to lv_sessions', function () {
    expect(config('session.table'))->toBe('lv_sessions');
});

it('cache database config points to lv_cache + lv_cache_locks', function () {
    expect(config('cache.stores.database.table'))->toBe('lv_cache');
    expect(config('cache.stores.database.lock_table'))->toBe('lv_cache_locks');
});

it('queue database config points to lv_jobs', function () {
    expect(config('queue.connections.database.table'))->toBe('lv_jobs');
});

it('failed queue config points to lv_failed_jobs', function () {
    expect(config('queue.failed.table'))->toBe('lv_failed_jobs');
});
