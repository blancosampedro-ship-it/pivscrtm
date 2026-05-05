# Bloque 12b.2 — Service `CalendarioLaboralService` (horarios L-V + festivos Madrid)

## Contexto

Bloque 12b.1 (PR #33, 3 may noche + smoke prod 4 may mañana) entregó la base geográfica:
6 zonas + 51 municipios asignados. Bloque 12b.2 entrega la **base temporal**: un service
puro PHP que responde a "¿este día es laborable? ¿cuántas horas? ¿cuál es el próximo día
hábil?". Sin UI, sin tablas nuevas, sin tocar PWA ni Filament.

El service será consumido en bloques posteriores:
- **12b.3** — cron mensual auto-genera filas `lv_revision_pendiente`. Necesita saber qué días
  del mes son hábiles para distribuir carga.
- **12b.4** — UI admin "Decisiones del día" filtra por `fecha_planificada == today` y
  promueve a `asignacion`. Necesita validar que today es laborable.
- **12b.5** — calendario operacional admin (vista mensual). Pinta días no-laborables (gris)
  + festivos (badge rojo).
- **Capacity calculations** generales: ~106h/semana × ~10 técnicos = X paneles/semana.

## Decisiones del usuario ya tomadas (status.md cierre 3 may)

- **Calendario laboral oficina/campo Winfin**:
  - L-Ma 7:00-15:00 (8h continuas, sin pausa).
  - Mi-J 8:00-14:00 + 15:00-18:00 (9h totales, **pausa de 14 a 15**).
  - V 8:00-14:00 (6h continuas).
  - S/D no laborable.
  - Total semanal: 8+8+9+9+6 = **40h**.
- **Festivos**: calendario municipal Madrid. Fuente operativa: nacionales + Comunidad de
  Madrid. (Festivos locales por-municipio — San Isidro Madrid Capital 15 mayo, Almudena 9
  nov, fiestas patronales de Móstoles/Alcalá/etc. — **NO** en este bloque. Decisión
  conservadora: contar más días hábiles que la realidad estricta. Mejor sobreestimar
  capacity que subestimar.)
- **Capacity escalable hasta 10 técnicos, hoy 1-2**.

## Restricciones inviolables

- **NO migrations**, **NO modelos nuevos**, **NO consultas BD**. Service puro stateless.
  Recibe `CarbonInterface` y constantes hardcoded. Si en el futuro hace falta editar
  festivos sin redeploy, refactor a tabla `lv_calendario_festivo` (no este bloque).
- **NO tocar UI** (Filament, PWA, blade views).
- **PHP 8.2 floor** (composer platform pin). Usar features de PHP 8.2: `readonly`
  properties, `Enum` (si aplica), `final class`. NO usar `Override` attribute (PHP 8.3+),
  NO usar `__PROPERTY_NAME__` magic constant (PHP 8.3+), NO usar JSON Path (PHP 8.3+).
- **Timezone hardcoded `Europe/Madrid`**. La app default es UTC (`config/app.php`). El
  service debe normalizar TODA fecha entrante a Europe/Madrid antes de extraer
  `dayOfWeek`/`month`/`day` — si no, un Carbon UTC del lunes a las 23:30 Europe/Madrid se
  interpretaría como martes 21:30 UTC y el cálculo fallaría. Test obligatorio cubre este
  caso.
- **Tests Pest verde obligatorio**. Suite actual 219. Sumar ~14-16 tests. Terminar ≥235
  verde.
- **CI verde** (3 jobs PHP 8.2/8.3 + frontend-build) antes de PR ready.
- **Cero dependencia nueva** en composer.json. Solo Carbon (ya está en Laravel) y nativo
  PHP.

## Plan de cambios

### Step 1 — Service `app/Services/CalendarioLaboralService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Calendario laboral Winfin — horarios oficina/campo + festivos Madrid.
 *
 * Service puro, stateless, sin BD. Consumed por:
 * - Bloque 12b.3 cron mensual (genera lv_revision_pendiente).
 * - Bloque 12b.4 UI "Decisiones del día" (filtra hoy laborable).
 * - Bloque 12b.5 calendario operacional admin.
 *
 * Festivos hardcodeados nacionales + Comunidad de Madrid 2026/2027. Sin festivos
 * locales por-municipio (decisión conservadora — overcount capacity).
 */
final class CalendarioLaboralService
{
    public const TZ = 'Europe/Madrid';

    /**
     * Slots horarios por día de semana (Carbon::MONDAY=1 .. SUNDAY=7).
     * Cada slot ['HH:MM', 'HH:MM']. Día sin slots = no laborable.
     *
     * Total semanal: 8+8+9+9+6 = 40h.
     */
    public const HORARIOS = [
        CarbonInterface::MONDAY    => [['07:00', '15:00']],                       // 8h
        CarbonInterface::TUESDAY   => [['07:00', '15:00']],                       // 8h
        CarbonInterface::WEDNESDAY => [['08:00', '14:00'], ['15:00', '18:00']],   // 9h
        CarbonInterface::THURSDAY  => [['08:00', '14:00'], ['15:00', '18:00']],   // 9h
        CarbonInterface::FRIDAY    => [['08:00', '14:00']],                       // 6h
        CarbonInterface::SATURDAY  => [],
        CarbonInterface::SUNDAY    => [],
    ];

    /**
     * Festivos Madrid (nacionales + Comunidad). Año 2026 + 2027 hardcoded.
     *
     * Variables litúrgicas (Jueves/Viernes Santo) calculadas para cada año.
     * Festivos locales por-municipio NO incluidos (overcount intencional).
     *
     * @var array<int, list<string>>  // year => list of 'YYYY-MM-DD'
     */
    public const FESTIVOS = [
        2026 => [
            '2026-01-01', // Año Nuevo
            '2026-01-06', // Reyes
            '2026-04-02', // Jueves Santo
            '2026-04-03', // Viernes Santo
            '2026-05-01', // Día Trabajador
            '2026-05-02', // Comunidad de Madrid (sábado — registrado igualmente)
            '2026-08-15', // Asunción (sábado)
            '2026-10-12', // Hispanidad (lunes)
            '2026-11-01', // Todos los Santos (domingo)
            '2026-12-06', // Constitución (domingo)
            '2026-12-08', // Inmaculada (martes)
            '2026-12-25', // Navidad (viernes)
        ],
        2027 => [
            '2027-01-01', // Año Nuevo (viernes)
            '2027-01-06', // Reyes (miércoles)
            '2027-03-25', // Jueves Santo
            '2027-03-26', // Viernes Santo
            '2027-05-01', // Día Trabajador (sábado)
            '2027-05-02', // Comunidad de Madrid (domingo)
            '2027-08-15', // Asunción (domingo)
            '2027-10-12', // Hispanidad (martes)
            '2027-11-01', // Todos los Santos (lunes)
            '2027-12-06', // Constitución (lunes)
            '2027-12-08', // Inmaculada (miércoles)
            '2027-12-25', // Navidad (sábado)
        ],
    ];

    /**
     * Normaliza a CarbonImmutable en Europe/Madrid.
     */
    private function normalize(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->setTimezone(self::TZ)->startOfDay();
    }

    public function isFestivo(CarbonInterface $date): bool
    {
        $d = $this->normalize($date);
        $year = $d->year;
        $iso = $d->format('Y-m-d');

        return isset(self::FESTIVOS[$year]) && in_array($iso, self::FESTIVOS[$year], true);
    }

    public function isLaborable(CarbonInterface $date): bool
    {
        $d = $this->normalize($date);
        $slots = self::HORARIOS[$d->dayOfWeekIso] ?? [];

        return $slots !== [] && ! $this->isFestivo($d);
    }

    /**
     * @return list<array{start: string, end: string}>  // [] si no laborable
     */
    public function slotsLaborables(CarbonInterface $date): array
    {
        if (! $this->isLaborable($date)) {
            return [];
        }
        $d = $this->normalize($date);
        $raw = self::HORARIOS[$d->dayOfWeekIso] ?? [];

        return array_map(
            static fn (array $slot): array => ['start' => $slot[0], 'end' => $slot[1]],
            $raw,
        );
    }

    public function horasLaborables(CarbonInterface $date): float
    {
        $total = 0.0;
        foreach ($this->slotsLaborables($date) as $slot) {
            [$sh, $sm] = explode(':', $slot['start']);
            [$eh, $em] = explode(':', $slot['end']);
            $start = ((int) $sh) * 60 + (int) $sm;
            $end = ((int) $eh) * 60 + (int) $em;
            $total += ($end - $start) / 60.0;
        }

        return $total;
    }

    /**
     * Devuelve la MISMA fecha si ya es laborable, o el próximo día hábil.
     * Util para "asigna a hoy si es hábil, si no al próximo día".
     */
    public function proximoDiaLaborable(CarbonInterface $date): CarbonImmutable
    {
        $d = $this->normalize($date);
        // Loop con safety (max 14 días = 2 semanas seguidas no laborables ya sería un bug).
        for ($i = 0; $i < 14; $i++) {
            if ($this->isLaborable($d)) {
                return $d;
            }
            $d = $d->addDay();
        }

        // Defensivo: nunca debería llegar (festivos consecutivos > 14d imposibles).
        // throw es preferible a return $date silencioso (fail-loud).
        throw new \RuntimeException(
            sprintf('No se encontró día laborable en 14 días desde %s. ¿Festivos mal configurados?', $date->format('Y-m-d')),
        );
    }

    /**
     * Cuenta días laborables entre $start y $end INCLUSIVOS.
     * Si $end < $start devuelve 0.
     */
    public function diasLaborablesEntre(CarbonInterface $start, CarbonInterface $end): int
    {
        $s = $this->normalize($start);
        $e = $this->normalize($end);
        if ($e->lt($s)) {
            return 0;
        }
        $count = 0;
        for ($d = $s; $d->lte($e); $d = $d->addDay()) {
            if ($this->isLaborable($d)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Suma horas laborables entre $start y $end INCLUSIVOS.
     */
    public function horasLaborablesEntre(CarbonInterface $start, CarbonInterface $end): float
    {
        $s = $this->normalize($start);
        $e = $this->normalize($end);
        if ($e->lt($s)) {
            return 0.0;
        }
        $total = 0.0;
        for ($d = $s; $d->lte($e); $d = $d->addDay()) {
            $total += $this->horasLaborables($d);
        }

        return $total;
    }
}
```

**Notas de implementación**:

1. `dayOfWeekIso` (Carbon) devuelve 1=Monday..7=Sunday, que coincide con `CarbonInterface::MONDAY` constants. NO usar `dayOfWeek` que es 0=Sunday..6=Saturday (UNIX-style) y rompería el array lookup.
2. `CarbonImmutable::instance($date)` acepta tanto `Carbon` mutable como `CarbonImmutable` y devuelve immutable — defensivo contra mutación accidental.
3. `setTimezone(self::TZ)->startOfDay()` garantiza que comparamos solo por fecha (00:00 Madrid local), independiente de la hora del input.
4. `proximoDiaLaborable` con loop max 14 días + throw defensivo: si llegamos a 14 días sin encontrar hábil, hay un bug en `FESTIVOS` (festivos consecutivos > 14d imposibles en Madrid).
5. La normalización a string ISO en `isFestivo` evita bugs de comparación entre `CarbonImmutable` con/sin tz.

### Step 2 — Tests Pest `tests/Unit/Services/CalendarioLaboralServiceTest.php`

Patrón: `tests/Unit/` (no `Feature/`) porque es service stateless sin BD ni HTTP. **NO debe necesitar `RefreshDatabase`**. Patrón consistente con `tests/Unit/AsignacionCierreServiceTest.php` si existe (verificar).

```php
<?php

declare(strict_types=1);

use App\Services\CalendarioLaboralService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->svc = new CalendarioLaboralService();
});

// === Días de la semana ===

it('lunes es laborable 8 horas en un slot 07-15', function () {
    $lunes = CarbonImmutable::parse('2026-05-04', 'Europe/Madrid'); // lunes
    expect($this->svc->isLaborable($lunes))->toBeTrue();
    expect($this->svc->horasLaborables($lunes))->toBe(8.0);
    expect($this->svc->slotsLaborables($lunes))->toBe([
        ['start' => '07:00', 'end' => '15:00'],
    ]);
});

it('martes es laborable 8 horas en un slot 07-15', function () {
    $martes = CarbonImmutable::parse('2026-05-05', 'Europe/Madrid');
    expect($this->svc->horasLaborables($martes))->toBe(8.0);
    expect($this->svc->slotsLaborables($martes))->toHaveCount(1);
});

it('miercoles es laborable 9 horas en dos slots con pausa 14-15', function () {
    $miercoles = CarbonImmutable::parse('2026-05-06', 'Europe/Madrid');
    expect($this->svc->horasLaborables($miercoles))->toBe(9.0);
    expect($this->svc->slotsLaborables($miercoles))->toBe([
        ['start' => '08:00', 'end' => '14:00'],
        ['start' => '15:00', 'end' => '18:00'],
    ]);
});

it('jueves es laborable 9 horas en dos slots', function () {
    $jueves = CarbonImmutable::parse('2026-05-07', 'Europe/Madrid');
    expect($this->svc->horasLaborables($jueves))->toBe(9.0);
    expect($this->svc->slotsLaborables($jueves))->toHaveCount(2);
});

it('viernes es laborable 6 horas en un slot 08-14', function () {
    $viernes = CarbonImmutable::parse('2026-05-08', 'Europe/Madrid');
    expect($this->svc->horasLaborables($viernes))->toBe(6.0);
    expect($this->svc->slotsLaborables($viernes))->toBe([
        ['start' => '08:00', 'end' => '14:00'],
    ]);
});

it('sabado no es laborable y devuelve 0 horas', function () {
    $sabado = CarbonImmutable::parse('2026-05-09', 'Europe/Madrid');
    expect($this->svc->isLaborable($sabado))->toBeFalse();
    expect($this->svc->horasLaborables($sabado))->toBe(0.0);
    expect($this->svc->slotsLaborables($sabado))->toBe([]);
});

it('domingo no es laborable', function () {
    $domingo = CarbonImmutable::parse('2026-05-10', 'Europe/Madrid');
    expect($this->svc->isLaborable($domingo))->toBeFalse();
});

// === Festivos ===

it('festivo Madrid nacional Año Nuevo no es laborable aunque sea entre semana', function () {
    $anyoNuevo = CarbonImmutable::parse('2026-01-01', 'Europe/Madrid'); // jueves
    expect($anyoNuevo->dayOfWeekIso)->toBe(CarbonImmutable::THURSDAY); // sanity
    expect($this->svc->isFestivo($anyoNuevo))->toBeTrue();
    expect($this->svc->isLaborable($anyoNuevo))->toBeFalse();
    expect($this->svc->horasLaborables($anyoNuevo))->toBe(0.0);
});

it('festivo Madrid Comunidad 2 mayo no es laborable', function () {
    $diaMadrid = CarbonImmutable::parse('2026-05-02', 'Europe/Madrid'); // sábado igualmente
    expect($this->svc->isFestivo($diaMadrid))->toBeTrue();
    expect($this->svc->isLaborable($diaMadrid))->toBeFalse();
});

it('jueves santo 2026 (calculado) no es laborable', function () {
    $jueSanto = CarbonImmutable::parse('2026-04-02', 'Europe/Madrid');
    expect($this->svc->isFestivo($jueSanto))->toBeTrue();
});

it('cualquier dia 2025 NO esta en FESTIVOS y no es flag festivo', function () {
    // 2025 no esta en el array. Comportamiento esperado: nunca festivo (devuelve laborable
    // si entre semana). Documenta el limite del hardcode.
    $cualquier = CarbonImmutable::parse('2025-05-15', 'Europe/Madrid');
    expect($this->svc->isFestivo($cualquier))->toBeFalse();
});

// === Próximo día laborable ===

it('proximo dia laborable salta fin de semana sabado a lunes', function () {
    $sabado = CarbonImmutable::parse('2026-05-09', 'Europe/Madrid');
    $proximo = $this->svc->proximoDiaLaborable($sabado);
    expect($proximo->format('Y-m-d'))->toBe('2026-05-11'); // lunes
});

it('proximo dia laborable salta festivo aislado en miercoles', function () {
    // 2026-05-01 es viernes festivo. Próximo laborable desde el viernes 1 mayo
    // debería ser el lunes 4 mayo (sábado y domingo no laborables).
    $viernesFestivo = CarbonImmutable::parse('2026-05-01', 'Europe/Madrid');
    $proximo = $this->svc->proximoDiaLaborable($viernesFestivo);
    expect($proximo->format('Y-m-d'))->toBe('2026-05-04'); // lunes
});

it('proximo dia laborable de un dia ya laborable devuelve la misma fecha', function () {
    $martes = CarbonImmutable::parse('2026-05-05', 'Europe/Madrid');
    $proximo = $this->svc->proximoDiaLaborable($martes);
    expect($proximo->format('Y-m-d'))->toBe('2026-05-05');
});

// === Rangos ===

it('dias laborables entre lunes y viernes de semana sin festivos = 5', function () {
    $l = CarbonImmutable::parse('2026-05-04', 'Europe/Madrid'); // lunes
    $v = CarbonImmutable::parse('2026-05-08', 'Europe/Madrid'); // viernes
    expect($this->svc->diasLaborablesEntre($l, $v))->toBe(5);
});

it('dias laborables entre lunes y domingo de semana sin festivos = 5 (excluye S/D)', function () {
    $l = CarbonImmutable::parse('2026-05-04', 'Europe/Madrid');
    $d = CarbonImmutable::parse('2026-05-10', 'Europe/Madrid');
    expect($this->svc->diasLaborablesEntre($l, $d))->toBe(5);
});

it('horas laborables semana completa sin festivos = 40h', function () {
    $l = CarbonImmutable::parse('2026-05-04', 'Europe/Madrid');
    $d = CarbonImmutable::parse('2026-05-10', 'Europe/Madrid');
    expect($this->svc->horasLaborablesEntre($l, $d))->toBe(40.0);
});

it('horas laborables semana con festivo viernes 1 mayo = 34h (40 menos 6 viernes)', function () {
    // Semana del 27 abril a 3 mayo 2026: lunes 27 (8h), martes 28 (8h), miércoles 29 (9h),
    // jueves 30 (9h), viernes 1 mayo FESTIVO (0h), sábado 2 (Madrid Comunidad, sábado
    // igualmente 0h), domingo 3 (0h). Total = 34h.
    $l = CarbonImmutable::parse('2026-04-27', 'Europe/Madrid');
    $d = CarbonImmutable::parse('2026-05-03', 'Europe/Madrid');
    expect($this->svc->horasLaborablesEntre($l, $d))->toBe(34.0);
});

it('rango invertido devuelve 0', function () {
    $a = CarbonImmutable::parse('2026-05-10', 'Europe/Madrid');
    $b = CarbonImmutable::parse('2026-05-04', 'Europe/Madrid');
    expect($this->svc->diasLaborablesEntre($a, $b))->toBe(0);
    expect($this->svc->horasLaborablesEntre($a, $b))->toBe(0.0);
});

// === Timezone normalization (regla #1 prompt) ===

it('Carbon UTC del lunes a las 23:30 se interpreta como martes en Europe/Madrid', function () {
    // 2026-05-04 23:30 UTC == 2026-05-05 01:30 Europe/Madrid (CEST +02:00 en mayo).
    // El service debe normalizar a Madrid antes de extraer dayOfWeek.
    // Como ambos lunes y martes son laborables 8h, comparamos slots para distinguir.
    // Pero también podemos verificar isLaborable + horas idénticas (8h ambos días).
    // Para test: usamos un caso que cambie el resultado — domingo Madrid pero lunes UTC.
    $borderline = CarbonImmutable::parse('2026-05-03 23:30', 'UTC'); // domingo 23:30 UTC
    // 23:30 UTC + 2h CEST = 01:30 lunes 2026-05-04 Madrid → laborable
    expect($this->svc->isLaborable($borderline))->toBeTrue();
    expect($this->svc->horasLaborables($borderline))->toBe(8.0);
});

it('Carbon mutable se acepta sin mutar el input', function () {
    $lunes = Carbon::parse('2026-05-04', 'Europe/Madrid');
    $original = $lunes->format('Y-m-d H:i:s');
    $this->svc->isLaborable($lunes);
    $this->svc->proximoDiaLaborable($lunes);
    expect($lunes->format('Y-m-d H:i:s'))->toBe($original); // input intacto
});
```

### Step 3 — Smoke local (text-only, no UI)

Después de tests verde, lanzar `php artisan tinker` y ejecutar:

```php
use App\Services\CalendarioLaboralService;
use Carbon\CarbonImmutable;

$svc = new CalendarioLaboralService();

echo 'Hoy ' . CarbonImmutable::now('Europe/Madrid')->format('Y-m-d (l)') . ': '
    . ($svc->isLaborable(CarbonImmutable::now('Europe/Madrid')) ? 'LABORABLE' : 'no laborable')
    . PHP_EOL;

echo 'Horas hoy: ' . $svc->horasLaborables(CarbonImmutable::now('Europe/Madrid')) . PHP_EOL;

$start = CarbonImmutable::parse('2026-05-01', 'Europe/Madrid');
$end = CarbonImmutable::parse('2026-05-31', 'Europe/Madrid');
echo 'Mayo 2026: ' . $svc->diasLaborablesEntre($start, $end) . ' días laborables = '
    . $svc->horasLaborablesEntre($start, $end) . 'h totales' . PHP_EOL;

$navidad = CarbonImmutable::parse('2026-12-25', 'Europe/Madrid');
echo 'Próximo laborable post-Navidad: ' . $svc->proximoDiaLaborable($navidad)->format('Y-m-d (l)') . PHP_EOL;
```

**Resultado esperado** (validar manualmente, NO commitear screenshots, son text-only):

- Hoy 2026-05-04 (Monday): LABORABLE
- Horas hoy: 8
- Mayo 2026: ~20 días laborables, ~163h totales (depende de cuántos festivos caen entre semana — el 1 viernes y el 2 sábado).
- Próximo laborable post-Navidad: 2026-12-28 (Monday) (Navidad viernes → fin de semana → lunes 28).

Si el output coincide con la intuición, el smoke pasa. Si no, debug en el service no en el test.

## DoD (Definition of Done)

- [ ] `app/Services/CalendarioLaboralService.php` creado con `final class`, constantes
      públicas `TZ`, `HORARIOS`, `FESTIVOS`, métodos públicos (`isFestivo`,
      `isLaborable`, `slotsLaborables`, `horasLaborables`, `proximoDiaLaborable`,
      `diasLaborablesEntre`, `horasLaborablesEntre`).
- [ ] Constantes hardcoded para 2026 + 2027 con festivos correctos (revisar lista contra
      calendario oficial Comunidad de Madrid en review — el usuario verifica).
- [ ] Service NO consume BD. NO depende de paquetes nuevos.
- [ ] `tests/Unit/Services/CalendarioLaboralServiceTest.php` con ~16 tests cubriendo:
      cada día semana × horarios, festivos nacional+Madrid, próximo día (3 escenarios),
      rangos (5 escenarios), normalización timezone (2 tests).
- [ ] `php artisan test` verde. Suite total 219 → ≥235 verde.
- [ ] CI 3/3 verde sobre el branch (PHP 8.2/8.3 + frontend-build).
- [ ] Pint format clean (`./vendor/bin/pint --test`).
- [ ] PHPStan/Psalm (si hay) clean.
- [ ] Smoke local ejecutado y output text validado contra expectativa.
- [ ] PR descripción menciona: lista festivos Madrid 2026/2027 + decisión de NO incluir
      festivos locales por-municipio + horarios L-V completos.

## Smoke real obligatorio post-merge

Sub-bloque puro de servicio sin UI ni BD → smoke real es **el `tinker` text del Step 3**.
NO requiere browser ni datos prod. Los tests Pest cubren todo el comportamiento porque
el service es stateless. Cero dependencia externa que pueda romper en prod.

## Riesgos y decisiones diferidas a review (cubrir en REPORTE FINAL)

1. **Lista exacta de festivos Madrid 2026/2027**: el array hardcoded refleja los
   festivos publicados oficialmente por el BOCM (Boletín Oficial de la Comunidad de
   Madrid). Si el calendario oficial 2026 incluye **traslados** (ej. el 6 dic 2026
   domingo se traslada al lunes 7) o festivos especiales no listados, el usuario debe
   añadirlos antes de mergear. Doc fuente: BOCM publicación anual del calendario laboral.
   Decisión conservadora del bloque: solo nacionales + 2 mayo Comunidad, sin traslados.
2. **Festivos locales por-municipio**: NO incluidos. San Isidro Madrid Capital (15 mayo),
   Almudena (9 nov), patronales de Móstoles/Alcalá/etc. quedan FUERA. Esta es una
   sobreestimación deliberada de capacity (~2-4 días/año/municipio extra contados como
   laborables). Si en el futuro la operación lo requiere, se refactoriza a tabla
   `lv_calendario_festivo` con columna `municipio_modulo_id` opcional (festivo de
   alcance comunidad si NULL, local si !NULL).
3. **Año 2028+**: el array no cubre 2028. Cuando se acerque, añadir bloque mini
   "actualizar festivos" con commit de 1 línea. Si el service se invoca con fecha 2028
   antes de actualizar, **`isFestivo` devolverá `false`** para festivos reales de ese
   año (degradación silenciosa, no crash). Riesgo aceptado: bug de capacity overestimate
   ~12 días/año hasta que se actualice. Mitigación: añadir un test que falle si
   `now()->year > 2027 && !isset(FESTIVOS[now()->year])` — opcional, decisión user.
4. **Timezone DST (cambio horario)**: España cambia hora último domingo de marzo
   (CET+1 → CEST+2) y último domingo de octubre (CEST+2 → CET+1). Como el service
   trabaja con `startOfDay()` y compara solo fecha, DST NO afecta. Test podría
   añadirse para confirmar (un domingo de cambio horario no debe romper).
5. **PHP 8.2 floor**: comprobar que los features usados (`final class`, `readonly`
   properties si hubiera, `CarbonInterface::MONDAY` constant, `dayOfWeekIso`) están en
   8.2. Confirmado via Carbon 2.x docs.

## REPORTE FINAL (formato esperado)

Tras ejecutar todos los Steps + DoD + Smoke local, redactar reporte con esta estructura:

```
## Bloque 12b.2 — REPORTE FINAL

### Estado
- Branch: bloque-12b2-calendario-laboral-service
- Commits: N
- Tests: 219 → 235 verde (16 tests nuevos en tests/Unit/Services/)
- CI: 3/3 verde sobre HEAD <hash>
- Pint: clean
- Smoke tinker: ejecutado, output coincide con expectativa

### Decisiones tomadas
- Festivos hardcoded año 2026 + 2027 (16 fechas).
- Sin festivos locales por-municipio.
- Sin traslados festivos automáticos (BOCM ad-hoc).
- Timezone Europe/Madrid forzado.

### Riesgos/pendientes para review
- Verificar contra BOCM 2026 oficial.
- 2028+ requiere update array.

### Pivots respecto al prompt
- (si los hubo, listar y justificar)
```

---

## Aplicación de la checklist obligatoria (memoria proyecto)

| Sección | Aplicado | Cómo |
|---|---|---|
| 1. Compatibilidad framework | ✓ | Service puro PHP 8.2 + Carbon. Sin Filament, sin Livewire, sin RelationManager (los 3 conflictivos del proyecto). Cero quirks esperados. |
| 2. Inferir de app vieja | N/A | App vieja PHP 2014 NO tiene calendario laboral integrado. Feature nueva 100%. |
| 3. Smoke real obligatorio | ✓ | Service sin UI → smoke real es `tinker` text-only. Tests Pest cubren todo el comportamiento porque service es stateless sin dependencias. |
| 4. Test pivots = banderazo rojo | ✓ | El bloque NO permite pivots de tests porque los casos son matemáticos (días+horas calculables). Si Copilot pivota, es señal de que el calendario está mal modelado. |
| 5. Datos prod-shaped | N/A | Service no consume BD. Recibe Carbon. Tests con dates fijas (2026-05-04 lunes, etc.) son representativas porque el universo es discreto (semana × año). |
