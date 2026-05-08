# Bloque 12h fix — incluir STATUS_NO_RESUELTO en items derivables

> **Eres VS Code Copilot en modo Agent**.
> Mini-PR de bug fix UX detectado en smoke real prod 12h (mismo patrón
> consolidado #47 del 12f).

## Bug detectado durante smoke real prod

Cuando un técnico cierra un item desde la PWA marcando "No resuelto", el
`RutaDiaItemCierreService` deja `lv_ruta_dia_item.status='no_resuelto'`
(con `causa_no_resolucion` populado). Este es exactamente el **trigger A**
que el Bloque 12h debía cubrir: admin tipifica la causa estructurada y
crea derivación.

Sin embargo, en el código actual:

- `DerivacionService::derivar()` (`app/Services/DerivacionService.php:35`)
  valida `whereIn(['pendiente','cerrado'])` → **excluye `no_resuelto`** →
  `DomainException`.
- `LvDerivacionResource::itemOptions()` (`app/Filament/Resources/LvDerivacionResource.php:376`)
  filtra `whereIn('status', ['pendiente','cerrado'])` → el item ni
  siquiera aparece en el selector "Item" del modal.

**Consecuencia**: trigger A real (técnico cierra `no_resuelto` desde PWA →
admin tipifica) está bloqueado en producción.

Los tests Pest existentes pasaron porque las factories crean items con
`status='pendiente'` o `'cerrado'` directamente, sin pasar por el flow
real PWA de cierre `no_resuelto`. Patrón consolidado: smoke real prod
sigue cazando lo que tests no.

## Fix consolidado

### 1. Añadir constante `STATUSES_DERIVABLES` en `LvRutaDiaItem`

En `app/Models/LvRutaDiaItem.php`, justo después de las otras constantes
`STATUS_*` y antes de los métodos:

```php
public const STATUSES_DERIVABLES = [
    self::STATUS_PENDIENTE,
    self::STATUS_CERRADO,
    self::STATUS_NO_RESUELTO,
];
```

### 2. Usar la constante en `DerivacionService::derivar()`

En `app/Services/DerivacionService.php` línea ~35, sustituir:

```php
if (! in_array($item->status, [LvRutaDiaItem::STATUS_PENDIENTE, LvRutaDiaItem::STATUS_CERRADO], true)) {
    throw new DomainException('El item no está en un estado derivable');
}
```

por:

```php
if (! in_array($item->status, LvRutaDiaItem::STATUSES_DERIVABLES, true)) {
    throw new DomainException('El item no está en un estado derivable');
}
```

### 3. Usar la constante en `LvDerivacionResource::itemOptions()`

En `app/Filament/Resources/LvDerivacionResource.php` línea ~376,
sustituir:

```php
->whereIn('status', [LvRutaDiaItem::STATUS_PENDIENTE, LvRutaDiaItem::STATUS_CERRADO])
```

por:

```php
->whereIn('status', LvRutaDiaItem::STATUSES_DERIVABLES)
```

## Tests Pest a añadir

### En `tests/Unit/Services/DerivacionServiceTest.php`

Añadir nuevo test:

```php
it('permite derivar item con status no_resuelto (trigger A real PWA)', function (): void {
    $item = LvRutaDiaItem::factory()->create([
        'status' => LvRutaDiaItem::STATUS_NO_RESUELTO,
        'causa_no_resolucion' => 'Acceso bloqueado por obras del ayuntamiento',
    ]);
    $admin = User::factory()->create();

    $deriv = app(DerivacionService::class)->derivar($item, [
        'tipo_causa' => LvDerivacion::CAUSA_AUTORIZACION,
        'actor_responsable' => LvDerivacion::ACTOR_AYUNTAMIENTO,
        'actor_notas' => 'Esperar fin de obras',
        'notas_derivacion' => 'Trigger A - tecnico cerró no_resuelto, admin tipifica',
    ], $admin);

    expect($deriv->status)->toBe(LvDerivacion::STATUS_PENDIENTE_TERCERO)
        ->and($item->fresh()->status)->toBe(LvRutaDiaItem::STATUS_DERIVADO);
});
```

### En `tests/Feature/Filament/LvDerivacionResourceTest.php`

Añadir test que valide que el `itemOptions` selector incluye items con
`status=no_resuelto`:

```php
it('item selector incluye items con status no_resuelto para trigger A', function (): void {
    $itemNoResuelto = LvRutaDiaItem::factory()->create([
        'status' => LvRutaDiaItem::STATUS_NO_RESUELTO,
        'causa_no_resolucion' => 'Acceso bloqueado',
    ]);

    actingAs(adminUser());

    Livewire::test(ListLvDerivaciones::class)
        ->callTableHeaderAction('nuevaDerivacion')
        // Asegurar que el item aparece en las opciones del select searchable
        // (usar el método que use Copilot — el patrón existente del file)
        ->assertSeeText((string) $itemNoResuelto->id);
});
```

(Si Filament 3 + searchable Select no permite assert directo del option,
basta con el test del service arriba — añade nota en el reporte.)

## DoD del mini-PR

- `pest --parallel` → suite total verde (debe quedar 419 tests con los 2 nuevos).
- `vendor/bin/pint --test` clean.
- Smoke local: `php artisan serve` + `curl -sI /admin/derivaciones` → 200/302 OK.
- Branch: `bloque-12h-fix-derivable-statuses`.
- Commit message tipo:
  `fix(derivacion): incluir STATUS_NO_RESUELTO en items derivables`
- PR title: `fix(derivacion): incluir STATUS_NO_RESUELTO en items derivables`
- PR body breve mencionando el bug detectado en smoke real prod 12h.

## Reporte final

Cuando termines, pega aquí:

```
## Reporte fix 12h

**Branch**: bloque-12h-fix-derivable-statuses
**Commit**: <hash>
**PR**: <url>

**Cambios**:
- LvRutaDiaItem: nueva constante STATUSES_DERIVABLES (3 valores)
- DerivacionService::derivar() usa la constante
- LvDerivacionResource::itemOptions() usa la constante

**Tests**: 2 nuevos. Suite total: <N> verde. Pint clean.
**Smoke local**: ✓ curl 302 + sin 500
```
