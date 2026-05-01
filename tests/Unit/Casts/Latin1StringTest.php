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

it('reverses prod-style double-encoded mojibake on get', function () {
    // Input simula los bytes que MySQL devuelve via conexión utf8mb4 cuando lee
    // una columna latin1 con texto doblemente encoded (patrón real observado
    // en producción 1 may 2026, ADR-0011).
    //
    // BD prod: bytes utf8 (c3 a1 = "á") almacenados como 2 chars latin1.
    // Connection transcoding: cada char latin1 -> utf8 -> 4 bytes c3 83 c2 a1.
    // PHP recibe esos 4 bytes y muestra "Ã¡" si no se aplica cast.
    $prodBytes = "Alcal\xc3\x83\xc2\xa1 de Henares";

    expect($this->cast->get($this->model, 'col', $prodBytes, []))
        ->toBe('Alcalá de Henares');
});

it('set produces legacy-compatible bytes for storage', function () {
    // Tras set(), los bytes deben ser tales que MySQL los transcoda via
    // utf8mb4 connection -> latin1 column como `c3 a1` (mismo patrón legacy
    // que la app vieja produce).
    $cleaned = $this->cast->set($this->model, 'col', 'Alcalá de Henares', []);

    // "Alcal" = 5 bytes ASCII, después "á" debe ocupar 4 bytes c3 83 c2 a1.
    expect(substr($cleaned, 5, 4))->toBe("\xc3\x83\xc2\xa1");
});

it('preserves pure ASCII strings unchanged', function () {
    $original = 'parada-cod-1234';
    $stored = $this->cast->set($this->model, 'col', $original, []);
    $read = $this->cast->get($this->model, 'col', $stored, []);

    expect($stored)->toBe($original);
    expect($read)->toBe($original);
});
