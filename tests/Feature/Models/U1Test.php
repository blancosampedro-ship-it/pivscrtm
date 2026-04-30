<?php

declare(strict_types=1);

use App\Models\U1;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses user_id as primary key (ADR-0008)', function () {
    $u = U1::factory()->create(['user_id' => 1, 'username' => 'admin']);

    expect(U1::find(1))->not->toBeNull();
    expect(U1::find(1)->username)->toBe('admin');
});

it('hides password by default', function () {
    $u = U1::factory()->create(['password' => sha1('s3cr3t')]);

    expect($u->toArray())->not->toHaveKey('password');
    expect($u->toJson())->not->toContain(sha1('s3cr3t'));
});
