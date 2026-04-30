<?php

declare(strict_types=1);

use App\Casts\Latin1String;
use Illuminate\Database\Eloquent\Model;

/**
 * Tests del cast Latin1String. Funcionan sin BD: el cast no toca el modelo.
 */
beforeEach(function () {
    $this->cast = new Latin1String;
    $this->model = new class extends Model
    {
        protected $table = 'fake';
    };
});

it('returns null when value is null on get', function () {
    expect($this->cast->get($this->model, 'col', null, []))->toBeNull();
});

it('returns null when value is null on set', function () {
    expect($this->cast->set($this->model, 'col', null, []))->toBeNull();
});

it('roundtrips Spanish characters through set then get', function () {
    $original = 'Móstoles, Cádiz, Alcalá';
    $stored = $this->cast->set($this->model, 'col', $original, []);
    $read = $this->cast->get($this->model, 'col', $stored, []);

    expect($read)->toBe($original);
});

it('roundtrips a tilde-only string', function () {
    $original = 'ñoño';
    $stored = $this->cast->set($this->model, 'col', $original, []);
    expect($this->cast->get($this->model, 'col', $stored, []))->toBe($original);
});

it('roundtrips uppercase accented characters', function () {
    $original = 'ÁÉÍÓÚÑ';
    $stored = $this->cast->set($this->model, 'col', $original, []);
    expect($this->cast->get($this->model, 'col', $stored, []))->toBe($original);
});

it('reverses pre-existing mojibake on get (legacy data scenario)', function () {
    // Simula bytes latin1 de "Móstoles" leídos como utf8 → "Móstoles" (mojibake).
    // El cast en .get debe convertirlo de vuelta a "Móstoles" limpio.
    $latin1Bytes = mb_convert_encoding('Móstoles', 'ISO-8859-1', 'UTF-8');

    expect($this->cast->get($this->model, 'col', $latin1Bytes, []))->toBe('Móstoles');
});

it('preserves pure ASCII strings unchanged', function () {
    $original = 'parada-cod-1234';
    $stored = $this->cast->set($this->model, 'col', $original, []);
    $read = $this->cast->get($this->model, 'col', $stored, []);

    expect($stored)->toBe($original);
    expect($read)->toBe($original);
});
