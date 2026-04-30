# 0007 — Validación de `piv.municipio` y referencia a `modulo`

- **Status**: Accepted
- **Date**: 2026-04-30

## Context

`piv.municipio` es `varchar(255)` (no INT, no FK física). Las copilot-instructions originales planteaban validar con `Rule::exists('modulo','id')`. Tras inspección de producción 2026-04-30 (Bloque 02):

1. **El nombre de la PK es `modulo_id`**, no `id`. La regla `Rule::exists('modulo','id')` falla en runtime.
2. **`modulo` es polimórfico con 12 tipos**, NO un solo catálogo. Sin filtro `tipo`, una validación contra `modulo` aceptaría cualquier valor (industria, marca de pantalla, check de revisión, etc.). Vergonzoso pero seguro de fallar.
3. **`piv.municipio` apunta específicamente a `modulo` con `tipo=5`** (103 valores: Madrid, Alcalá de Henares, Móstoles, etc.).
4. **Existe el centinela `"0"`** en 94 paneles (de los 575 totales — 16%) que significa "sin municipio asignado". `modulo_id=0` NO existe en la tabla; es un valor hard-coded en la app vieja. Una validación estricta `exists` rechaza estos 94 paneles, lo cual rompería cualquier UPDATE de panel preexistente.

Datos reales (2026-04-30):

```
Total filas piv: 575
Distinct municipio: 103 (excluyendo "0")
"0" como municipio: 94 filas (16.3%)
Valores no-numéricos en municipio: 0 (todos son strings que contienen ints o "0")
```

## Decision

Implementar la validación de `piv.municipio` como regla custom que acepta:

1. El centinela `"0"` (sin municipio asignado) explícitamente.
2. Cualquier `modulo_id` numérico que **además** tenga `tipo=5`.

### Implementación

**Modelo `Modulo`** (Bloque 03):

```php
class Modulo extends Model
{
    protected $table = 'modulo';
    protected $primaryKey = 'modulo_id';
    public $timestamps = false;

    /** Constantes de tipos (descubiertos 2026-04-30) */
    public const TIPO_INDUSTRIA      = 1;
    public const TIPO_PANTALLA       = 2;
    public const TIPO_MARQUESINA     = 3;
    public const TIPO_ALIMENTACION   = 4;
    public const TIPO_MUNICIPIO      = 5;
    public const TIPO_ESTADO_PIV     = 6;
    public const TIPO_CHECK_ASPECTO        = 9;
    public const TIPO_CHECK_FUNCIONAMIENTO = 10;
    public const TIPO_CHECK_ACTUACION      = 11;
    public const TIPO_CHECK_AUDIO          = 12;
    public const TIPO_CHECK_FECHA_HORA     = 13;
    public const TIPO_CHECK_PRECISION_PASO = 14;

    public function scopeMunicipios($q) { return $q->where('tipo', self::TIPO_MUNICIPIO); }
    public function scopeIndustrias($q) { return $q->where('tipo', self::TIPO_INDUSTRIA); }
    // ... etc
}
```

**FormRequest** para crear/actualizar PIV (Bloque 07):

```php
public function rules(): array
{
    return [
        'municipio' => [
            'required',
            'string',
            function (string $attr, mixed $value, Closure $fail) {
                // Centinela "sin municipio"
                if ($value === '0') {
                    return;
                }
                // Debe ser numérico Y existir en modulo con tipo=5
                if (! ctype_digit($value)) {
                    $fail('El municipio debe ser un id numérico o "0" (sin municipio).');
                    return;
                }
                $exists = DB::table('modulo')
                    ->where('modulo_id', (int) $value)
                    ->where('tipo', Modulo::TIPO_MUNICIPIO)
                    ->exists();
                if (! $exists) {
                    $fail("El municipio id={$value} no existe en el catálogo (modulo tipo=5).");
                }
            },
        ],
        // ... otras reglas
    ];
}
```

**UI Filament Resource Piv** (Bloque 07):

```php
Forms\Components\Select::make('municipio')
    ->options(function () {
        return ['0' => '— Sin municipio asignado —']
            + Modulo::municipios()
                ->orderBy('nombre')
                ->pluck('nombre', 'modulo_id')
                ->mapWithKeys(fn ($n, $id) => [(string) $id => $n])
                ->all();
    })
    ->searchable()
    ->required()
    ->default('0')
```

Esto da al admin un dropdown de 103 municipios + opción explícita "Sin municipio asignado". Los 94 paneles existentes con `municipio="0"` quedan editables sin romper nada.

## Considered alternatives

- **`Rule::exists('modulo', 'modulo_id')->where('tipo', 5)`** — funciona técnicamente pero **rechaza el centinela `"0"`**. Requiere capa adicional para permitir "0", lo cual es exactamente lo que hace la closure custom propuesta. Equivalente, menos legible.
- **Migrar `piv.municipio` de varchar a INT con FK a `modulo.modulo_id`** — descartado: viola ADR-0002 (no modificar schema legacy). Y la app vieja sigue escribiendo "0" como string, así que cualquier conversión rompe coexistencia.
- **Crear tabla `lv_municipios`** independiente y dejar `piv.municipio` como referencia — descartado: ya existe `modulo` con los 103 municipios poblados desde 2014. Duplicar es trabajo y desincronización futura.
- **Limpiar los 94 paneles con `municipio="0"` antes de implementar la validación** (asignándoles municipio real) — descartado: requiere conocimiento de campo (¿dónde está el panel realmente?). Diferido a iniciativa de datos del cliente, no precondición técnica.

## Consequences

**Positivas:**
- Validación correcta sin tocar schema.
- UI clara con dropdown ordenado alfabéticamente y opción "sin asignar" explícita.
- Constantes `Modulo::TIPO_*` reutilizables en todos los Resources que filtran modulo (Bloque 07 PIV, Bloque 08 averías, Bloque 09 cierre asignación).
- Datos legacy (94 paneles con "0") siguen siendo editables, no quedan bloqueados.

**Negativas:**
- La validación es una closure, no una regla `Rule::exists` declarativa. Ligeramente más verboso. Hot path de validación de PIV no es crítico (admin crea/edita PIV manual ocasionalmente).
- Requiere tener el modelo `Modulo` con sus constantes ANTES del Bloque 07 (es decir, en el Bloque 03 de modelos Eloquent).

**Pendientes**:
- Investigar si las columnas `piv.tipo_piv`, `piv.tipo_marquesina`, `piv.tipo_alimentacion` son referencias lógicas a `modulo` tipos 2/3/4 o son texto libre. Capturado como TODO operacional (no bloquea Bloque 07).
- Confirmar el uso de `modulo` tipo=6 ("En Rev.", "OK", "Retirada"): probable que sea un mapeo legacy de `piv.status` (tinyint) a etiquetas humanas. Investigación capturada en TODO.
