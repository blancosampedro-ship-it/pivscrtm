# Bloque 11b — Cierre flow PWA técnico (online)

## Contexto

Bloque 11a (PR #25) entregó el shell PWA + login técnico + dashboard "Mis asignaciones abiertas".
Bloque 11ab (PR #26) entregó el admin TecnicoResource Filament para crear/gestionar técnicos.

Ambos mergeados en `main` el 3 may 2026 tras smoke real combinado de 12 puntos. Suite actual: **169 tests verde**, HEAD `f9c9644`.

Este bloque cierra el lazo: el técnico **abre la card de su asignación → completa el cierre desde el móvil → adjunta foto desde la cámara → submit**. Después el sistema graba en `correctivo`/`revision` legacy + `lv_correctivo_imagen` y pone `asignacion.status = 2`.

**El bloque NO incluye** Service Worker, offline, cola offline ni iconos PWA definitivos — eso es **Bloque 11c**, separado para que SW y cierre flow no compitan en smoke ni en review.

## Nota crítica sobre legacy

**No existe funcionalidad de técnico en la app vieja.** La app vieja `winfin.es/*.php` solo tiene admin + operador. El técnico actualmente recibe la asignación vía email/papel y reporta el cierre verbalmente o vía operador. Este flujo es **funcionalidad nueva en la app nueva**, no migración.

**Por tanto NO replicar legacy para el técnico** — no hay ground truth a la que adherirse. El diseño se basa en:
- ADR-0006 — schema legacy de `correctivo` reutilizado + `lv_correctivo_imagen` para fotos.
- ADR-0004 — field mapping correcto: NO escribir `averia.notas` (regla #3 RGPD: pertenece al operador).
- Form admin Bloque 09 (`AsignacionResource::cierreFormCorrectivo/Revision`) — fuente del schema y validaciones del cierre, ya validado en smoke real con asignación 32439.
- DESIGN.md §10.1 (mobile-first, tap-targets ≥44px, regla #11 stripe rojo/verde, Carbon Productive).
- UX mobile real de campo: técnico está fuera, posiblemente con manos sucias, sin teclado físico, con luz cambiante. Form **mínimo, robusto, evidencia visual**.

## Restricciones inviolables

- **ADR-0006**: `correctivo` solo recibe `recambios/diagnostico/estado_final/tiempo` + flags facturación. NO existen `accion/imagen/fecha/notas` en esa tabla.
- **ADR-0004 / Regla #3 RGPD**: NUNCA escribir `averia.notas` desde el cierre. Esa columna pertenece al operador que reportó la avería.
- **ADR-0004 / Regla #11**: stripe lateral red para correctivo, green para revisión, en cards y en el header del cierre form. Carbon Red 60 / Green 50.
- **Carbon visual** (DESIGN.md §10.1): sin border-radius en buttons/inputs, inputs bottom-border-only, sticky bottom CTA full-width tap-target ≥56px (más alto que los 44 mínimos por mobile field UX).
- **Reuso del cierre admin**: el handler `handleCierre/handleCierreCorrectivo/handleCierreRevision` actualmente vive privado en `AsignacionResource` (líneas 438-514). Se extrae a `App\Services\AsignacionCierreService` y se llama desde ambos sitios (admin Filament action + nuevo Volt PWA). **Cero divergencia de comportamiento** entre admin y PWA — mismo service, mismas validaciones, misma idempotencia.
- **Security boundary**: el técnico solo puede ver y cerrar SUS asignaciones (`asignacion.tecnico_id === auth()->user()->legacy_id`). Test obligatorio "técnico A no puede cerrar asignación de técnico B" (404 o 403).
- **Tests Pest verde obligatorio**, suite actual 169 tests, sumar ~12, terminar ≥181 verde.
- **CI verde** (PHP 8.2 + 8.3 + Vite) antes de PR ready.

## Plan de cambios

### Step 1 — Extraer `App\Services\AsignacionCierreService`

Crear `app/Services/AsignacionCierreService.php` (no existe `app/Services/` aún; crearlo).

Diseño de la API:

```php
namespace App\Services;

use App\Models\Asignacion;
use App\Models\Correctivo;
use App\Models\LvCorrectivoImagen;
use App\Models\Revision;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AsignacionCierreService
{
    /**
     * Cierra la asignación según su tipo. Idempotente: si ya hay correctivo
     * o revision asociado, lanza ValidationException con clave 'cerrar'.
     *
     * @param  array<string, mixed>  $data  Form data con la shape del cierre admin Bloque 09.
     * @return array{
     *   model: \App\Models\Correctivo|\App\Models\Revision,
     *   imagenes: \Illuminate\Database\Eloquent\Collection<int, \App\Models\LvCorrectivoImagen>
     * }
     */
    public function cerrar(Asignacion $asignacion, array $data): array
    {
        return DB::transaction(function () use ($asignacion, $data): array {
            $asignacion->refresh();

            // Idempotencia
            if ((int) $asignacion->tipo === Asignacion::TIPO_CORRECTIVO && $asignacion->correctivo()->exists()) {
                throw ValidationException::withMessages([
                    'cerrar' => 'Esta asignación ya tiene un correctivo registrado.',
                ]);
            }
            if ((int) $asignacion->tipo === Asignacion::TIPO_REVISION && $asignacion->revision()->exists()) {
                throw ValidationException::withMessages([
                    'cerrar' => 'Esta asignación ya tiene una revisión registrada.',
                ]);
            }

            $result = match ((int) $asignacion->tipo) {
                Asignacion::TIPO_CORRECTIVO => $this->cerrarCorrectivo($asignacion, $data),
                Asignacion::TIPO_REVISION   => $this->cerrarRevision($asignacion, $data),
                default => throw ValidationException::withMessages([
                    'cerrar' => 'Asignación con tipo desconocido. No se puede cerrar.',
                ]),
            };

            // NO tocar averia.notas (regla #3 RGPD).
            $asignacion->update(['status' => 2]);

            return $result;
        });
    }

    /** @param array<string, mixed> $data */
    private function cerrarCorrectivo(Asignacion $asignacion, array $data): array { /* ... */ }

    /** @param array<string, mixed> $data */
    private function cerrarRevision(Asignacion $asignacion, array $data): array { /* ... */ }
}
```

Mover los `handleCierreCorrectivo` y `handleCierreRevision` actuales (líneas 474-514 de `AsignacionResource`) a los métodos privados del service. Preservar todas las assignaciones de campos, defaults, parsing de fecha (Carbon), normalización de fotos.

**Refactor del admin Filament action**: en `AsignacionResource.php:227-235` el closure `->action(function (Asignacion $record, array $data) { self::handleCierre($record, $data); })` pasa a:

```php
->action(function (Asignacion $record, array $data) {
    app(AsignacionCierreService::class)->cerrar($record, $data);

    Notification::make()
        ->title('Cierre registrado')
        ->body('Asignación #'.$record->asignacion_id.' marcada como cerrada.')
        ->success()
        ->send();
})
```

Borrar los métodos privados `handleCierre`, `handleCierreCorrectivo`, `handleCierreRevision` de `AsignacionResource` (ya no se usan desde ahí).

**El test del Bloque 09** (`tests/Feature/Filament/AsignacionCierreTest.php` o similar) debe seguir verde sin modificación o con cambio mínimo. Si Copilot toca este test para "adaptarlo al refactor", **es banderazo rojo**: el comportamiento del action no cambia, solo la implementación interna. Si el test rompe es porque el service no replica fielmente el handler — fix el service, no el test.

### Step 2 — Cards del dashboard PWA navegables

`resources/views/livewire/tecnico/dashboard.blade.php` actualmente renderiza `<li>` no clickables con la info de cada asignación.

Convertir cada `<li>` en un `<a href="{{ route('tecnico.asignacion.cierre', $asignacion) }}">` (full card es tap target). Mantener el stripe lateral 4px regla #11. Mantener el contenido visual del Bloque 11a (kicker, panel #ID, dirección, subtítulo).

Añadir un indicador visual de "tappable" (chevron derecha pequeño en gray, `heroicon-m-chevron-right`).

### Step 3 — Ruta + Volt component cierre

**Ruta nueva** en `routes/web.php`, dentro del grupo `middleware('tecnico')->prefix('tecnico')->name('tecnico.')`:

```php
Volt::route('/asignaciones/{asignacion}', 'tecnico.cierre')->name('asignacion.cierre');
```

Route model binding: por defecto resolverá por `asignacion_id`. Si el binding requiere ajuste (custom column), usar `Asignacion::resolveRouteBinding()` o configurar en el routes group.

**Volt component** en `resources/views/livewire/tecnico/cierre.blade.php`. Estructura:

```php
<?php
use App\Models\Asignacion;
use App\Models\Tecnico;
use App\Services\AsignacionCierreService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\layout;
use function Livewire\Volt\title;

layout('components.tecnico.shell');
title('Winfin PIV — Cierre');

new class extends Component {
    use WithFileUploads;

    public Asignacion $asignacion;

    // Correctivo
    public string $diagnostico = '';
    public string $recambios = '';
    public string $estado_final = 'OK';
    public string $tiempo = '';

    // Revisión
    public string $fecha = '';
    public string $ruta = '';
    public string $fecha_hora = '';
    public string $aspecto = 'OK';
    public string $funcionamiento = 'OK';
    public string $actuacion = 'OK';
    public string $audio = 'OK';
    public string $lineas = 'OK';
    public string $precision_paso = 'OK';
    public string $notas = '';

    // Foto cámara (multi)
    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $fotos = [];

    public function mount(Asignacion $asignacion): void
    {
        // Security: el técnico solo ve sus propias asignaciones abiertas.
        $tecnicoId = (int) auth()->user()->legacy_id;
        abort_unless((int) $asignacion->tecnico_id === $tecnicoId, 403);
        abort_unless((int) $asignacion->status === 1, 410); // 410 Gone si ya cerrada

        $this->asignacion = $asignacion->load(['averia.piv']);

        if ((int) $asignacion->tipo === Asignacion::TIPO_REVISION) {
            $this->fecha = now()->format('Y-m-d');
        }
    }

    public function cerrar(AsignacionCierreService $service): void
    {
        $isCorrectivo = (int) $this->asignacion->tipo === Asignacion::TIPO_CORRECTIVO;

        $rules = $isCorrectivo
            ? [
                'diagnostico'   => ['required', 'string', 'max:255'],
                'recambios'     => ['required', 'string', 'max:255'],
                'estado_final'  => ['required', 'string', 'max:100'],
                'tiempo'        => ['nullable', 'string', 'max:45'],
                'fotos'         => ['nullable', 'array', 'max:10'],
                'fotos.*'       => ['image', 'max:8192'], // 8 MB
            ]
            : [
                'fecha'          => ['nullable', 'date'],
                'ruta'           => ['nullable', 'string', 'max:100'],
                'fecha_hora'     => ['nullable', 'string', 'max:100'],
                'aspecto'        => ['required', 'in:OK,KO,N/A'],
                'funcionamiento' => ['required', 'in:OK,KO,N/A'],
                'actuacion'      => ['required', 'in:OK,KO,N/A'],
                'audio'          => ['required', 'in:OK,KO,N/A'],
                'lineas'         => ['required', 'in:OK,KO,N/A'],
                'precision_paso' => ['required', 'in:OK,KO,N/A'],
                'notas'          => ['nullable', 'string', 'max:100'],
            ];

        $this->validate($rules);

        // Persistir fotos (mover de livewire-tmp a public/piv-images/correctivo)
        $fotosPaths = [];
        foreach ($this->fotos as $f) {
            $fotosPaths[] = $f->store('piv-images/correctivo', 'public');
        }

        $data = $isCorrectivo
            ? [
                'diagnostico'             => $this->diagnostico,
                'recambios'               => $this->recambios,
                'estado_final'            => $this->estado_final,
                'tiempo'                  => $this->tiempo ?: null,
                'fotos'                   => $fotosPaths,
                // Facturación: técnico NO toca, default false
                'contrato'                => false,
                'facturar_horas'          => false,
                'facturar_desplazamiento' => false,
                'facturar_recambios'      => false,
            ]
            : [
                'fecha'           => $this->fecha,
                'ruta'            => $this->ruta ?: null,
                'fecha_hora'      => $this->fecha_hora ?: null,
                'aspecto'         => $this->aspecto,
                'funcionamiento'  => $this->funcionamiento,
                'actuacion'       => $this->actuacion,
                'audio'           => $this->audio,
                'lineas'          => $this->lineas,
                'precision_paso'  => $this->precision_paso,
                'notas'           => $this->notas ?: null,
            ];

        try {
            $service->cerrar($this->asignacion, $data);
        } catch (ValidationException $e) {
            // idempotencia o tipo desconocido
            $this->addError('cerrar', collect($e->errors())->flatten()->first() ?? 'No se pudo cerrar.');
            return;
        }

        session()->flash('cierre_ok', 'Asignación #'.$this->asignacion->asignacion_id.' cerrada.');
        $this->redirect(route('tecnico.dashboard'), navigate: false);
    }
}; ?>

<div class="p-4 max-w-md mx-auto">
    {{-- Header con stripe regla #11 --}}
    @php
        $isCorrectivo = (int) $asignacion->tipo === \App\Models\Asignacion::TIPO_CORRECTIVO;
        $stripe = $isCorrectivo ? 'border-error' : 'border-success';
        $kicker = $isCorrectivo ? 'Cerrar avería real' : 'Cerrar revisión mensual';
        $piv = $asignacion->averia?->piv;
    @endphp

    <div class="border-l-4 {{ $stripe }} bg-layer-0 p-4 mb-4">
        <div class="text-xs uppercase tracking-wider text-ink-secondary font-medium mb-1">{{ $kicker }}</div>
        @if ($piv)
            <div class="text-md font-medium leading-tight">
                Panel #{{ str_pad((string) $piv->piv_id, 3, '0', STR_PAD_LEFT) }}
                <span class="font-mono text-sm text-ink-secondary ml-1">· {{ $piv->parada_cod }}</span>
            </div>
            <div class="text-sm text-ink-secondary leading-snug mt-1">{{ $piv->direccion }}</div>
        @endif
    </div>

    <form wire:submit="cerrar" class="space-y-5" enctype="multipart/form-data">
        @if ($isCorrectivo)
            {{-- Form correctivo --}}
            ...
            {{-- Foto cámara --}}
            <div>
                <label for="fotos" class="block text-xs uppercase tracking-wider text-ink-secondary font-medium mb-2">Fotos del cierre</label>
                <input type="file"
                       id="fotos"
                       wire:model="fotos"
                       accept="image/*"
                       capture="environment"
                       multiple
                       class="block w-full text-sm">
                @error('fotos.*') <p class="text-error text-sm mt-1">{{ $message }}</p> @enderror

                {{-- Preview thumbnails --}}
                @if (count($fotos) > 0)
                    <div class="grid grid-cols-3 gap-2 mt-3">
                        @foreach ($fotos as $f)
                            <img src="{{ $f->temporaryUrl() }}" class="w-full h-24 object-cover">
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            {{-- Form revisión --}}
            ...
        @endif

        @error('cerrar') <p class="text-error text-sm">{{ $message }}</p> @enderror

        <button type="submit"
                class="tap-target w-full bg-primary-60 hover:bg-primary-70 text-ink-on_color font-medium text-md py-4 transition-colors duration-fast-01 ease-carbon-productive">
            <span wire:loading.remove wire:target="cerrar">Registrar cierre</span>
            <span wire:loading wire:target="cerrar">Guardando...</span>
        </button>
    </form>
</div>
```

Detalles del form correctivo (mobile-first, layouts simples):
- Diagnóstico: `<textarea>` 3 rows, full width.
- Acción / Recambio: `<textarea>` 3 rows, full width.
- Estado final: `<input type="text">` con default `OK` (admin tiene opciones, aquí texto libre por simplicidad mobile).
- Tiempo (horas): `<input type="text" inputmode="decimal">` para que el teclado móvil sea numérico.
- Fotos: input file con `accept="image/*" capture="environment"` y multi.

Detalles del form revisión:
- Fecha: `<input type="date">` con default hoy.
- Ruta + Verificación fecha/hora: text inputs cortos.
- Checklist (6 selects nativos): aspecto, funcionamiento, actuacion, audio, lineas, precision_paso. Cada uno con OK/KO/N/A. En mobile se renderizan como dropdown nativo del SO — ideal para tap.
- Notas: textarea max 100 chars opcional.

### Step 4 — Mostrar success flash en dashboard

En `resources/views/livewire/tecnico/dashboard.blade.php`, al inicio del `<div>` raíz:

```blade
@if (session('cierre_ok'))
    <div class="bg-success-subtle text-success-text border-l-4 border-success p-3 mb-4 text-sm" role="status">
        {{ session('cierre_ok') }}
    </div>
@endif
```

### Step 5 — Tests obligatorios

Crear `tests/Unit/Services/AsignacionCierreServiceTest.php` y `tests/Feature/Tecnico/Bloque11bCierreFlowTest.php`.

**Service unit tests** (mínimo 6):
1. `service_creates_correctivo_with_correct_fields_for_tipo_1`.
2. `service_creates_revision_with_correct_fields_for_tipo_2`.
3. `service_sets_status_2_after_cierre`.
4. `service_does_not_touch_averia_notas` (regla #3 RGPD — leer averia.notas pre/post y assert idénticas).
5. `service_throws_if_correctivo_already_exists` (idempotencia).
6. `service_creates_lv_correctivo_imagen_rows_for_uploaded_paths` (multi-foto).

**Feature PWA tests** (mínimo 6):
1. `tecnico_can_view_their_own_open_asignacion_cierre_page` (200, contiene panel info).
2. `tecnico_cannot_view_other_tecnico_asignacion` (403).
3. `tecnico_cannot_view_already_closed_asignacion` (410).
4. `tecnico_can_submit_correctivo_cierre_creates_records_and_redirects_with_flash` — incluyendo upload de foto vía `Storage::fake('public')` + `UploadedFile::fake()->image('foto.jpg')`. Assert: correctivo creado con campos correctos, lv_correctivo_imagen creada con path en `piv-images/correctivo/`, status=2, redirect a /tecnico, flash `cierre_ok`.
5. `tecnico_can_submit_revision_cierre_creates_revision_and_redirects` — sin foto.
6. `tecnico_idempotent_cierre_shows_error_on_second_submit` (admin ya cerró desde Filament en mientras → técnico ve error inline).

**Refactor test admin Bloque 09**: NO modificar excepto si es estrictamente necesario para acomodar el service. Si Copilot rompe este test, **detener el bloque y diagnosticar** — el comportamiento del action no cambia. Si la refactorización del Volt PWA hace tropezar a un test admin, hay un bug en la extracción del service.

**Tests pivots = banderazo rojo**:
- Si Copilot dice "tuve que mockear el service en el test PWA" → fail. El service debe correr real con `Storage::fake()` y BD test.
- Si dice "no encontré forma de subir foto en test Volt" → ofrecer pista: `Livewire::test('tecnico.cierre', ['asignacion' => $a])->set('fotos', [UploadedFile::fake()->image('p.jpg')])->call('cerrar')`.
- Si dice "salté el test de seguridad técnico-A-vs-B porque era complejo" → fail. Es el test crítico de RGPD del bloque.

### Step 6 — Smoke real obligatorio

Pre-requisitos:
- Suite Pest verde local + CI verde.
- BD prod: técnico smoke `id=66 'Smoke Test Once Once'` actualmente status=0 (post Parte C del 11ab).

Pasos del smoke:

1. **Reactivar técnico smoke desde admin** (`/admin/tecnicos`, kebab fila id=66 → Activar). Verificar BD `Tecnico::find(66)->status === 1`.

2. **Crear o reasignar una asignación abierta tipo=1 a técnico 66**:
   - Opción A (limpia): admin asigna manualmente desde `/admin/asignaciones/create` (si esa ruta existe).
   - Opción B (vía tinker, si no hay UI admin para asignar): seleccionar una asignación cerrada reciente, crear una **nueva** vía `Asignacion::create([...])` apuntando al técnico 66 con status=1 sobre un panel real. Documentar el ID en notas del PR para cleanup post-merge.
   - Opción C (más realista): tomar una asignación legacy abierta de prod (en este momento puede no haber ninguna abierta, ver Bloque 09 smoke "0 paneles abiertos"). Si no hay → opción B.

3. **Login PWA con técnico smoke** desde Safari ventana privada o navegador secundario:
   - Email: `test.smoke11@winfin.local`
   - Password: `<SMOKE_PASS>` (ver `docs/runbooks/.smoke-credentials.local.md`, gitignored)

4. **Verificar dashboard**: la card de la asignación creada en paso 2 debe aparecer con stripe color regla #11.

5. **Tap card**: navega a `/tecnico/asignaciones/{id}`. Renderiza el form correctivo (si tipo=1) o revisión (si tipo=2). Header con stripe + panel info correctos.

6. **Rellenar form correctivo** con datos plausibles:
   - Diagnóstico: `Smoke 11b — fallo en alimentación`
   - Acción/Recambio: `Sustituida fuente`
   - Estado final: `OK`
   - Tiempo: `1.5`
   - Fotos: subir 1 imagen real desde el file picker (en desktop) o cámara (en móvil).

7. **Submit**. Esperado:
   - Redirect a `/tecnico` con flash success "Asignación #{id} cerrada."
   - La card de esa asignación ya NO aparece (status=2, dashboard filtra solo abiertas).

8. **Verificar BD via tinker**:
   ```bash
   php artisan tinker --execute="
   \$a = App\Models\Asignacion::find(<id>);
   echo 'status: ' . \$a->status . PHP_EOL;
   \$c = \$a->correctivo;
   echo 'correctivo_id: ' . \$c->correctivo_id . PHP_EOL;
   echo 'tecnico_id: ' . \$c->tecnico_id . ' (esperado 66)' . PHP_EOL;
   echo 'diagnostico: ' . \$c->diagnostico . PHP_EOL;
   echo 'recambios: ' . \$c->recambios . PHP_EOL;
   echo 'fotos: ' . \$c->imagenes->count() . PHP_EOL;
   foreach (\$c->imagenes as \$img) echo '  - ' . \$img->url . PHP_EOL;
   echo 'averia.notas pre/post igual: revisar manualmente con BD pre-cierre' . PHP_EOL;
   "
   ```
   Esperado: status=2, correctivo creado con tecnico_id=66, diagnostico/recambios correctos, ≥1 imagen.

9. **Verificar archivo en disco**: `ls -la storage/app/public/piv-images/correctivo/` — debe haber el archivo de la foto.

10. **(Opcional) Repetir el flow con tipo=2 revisión** si hay alguna asignación tipo=2 abierta o se puede crear. Verificar `revision` row con checklist OK + `notas` opcional vacía.

Si el smoke pasa los 8 puntos → bloque DoD cumplida → mergeable.

Si falla → mini-bloque 11b-fix con diagnóstico específico.

## Restricciones de proceso (CLAUDE.md)

- Branch: `bloque-11b-pwa-tecnico-cierre`.
- Commits atómicos: 1) extracción service + refactor admin, 2) ruta + Volt cierre form, 3) cards tappables + flash, 4) tests service, 5) tests feature PWA. Cada commit con mensaje conventional.
- Push a la rama, PR contra `main` con descripción detallada (qué se entrega, qué verifica, qué tests añade). NO mergear: el usuario revisa y mergea desde GitHub web.
- NO modificar `app/Auth/`, `app/Models/` (excepto añadir relaciones nuevas si imprescindible — primero discutirlo en el reporte final), `lang/`, `config/`.
- **NO tocar nada del Bloque 11c** (Service Worker, manifest avanzado, offline). Eso es bloque separado.
- **NO** fix de las 3 deudas registradas hoy (`tecnico_id` race condition, kebab clip, throttle UX) — son chips spawned post-merge en otros worktrees.

## Reporte final

Al terminar, devolver:

```
## Bloque 11b — Reporte

### Commits
- <hash> feat(services): extract AsignacionCierreService from admin handler
- <hash> refactor(filament): admin Asignacion close action delegates to service
- <hash> feat(tecnico): add /tecnico/asignaciones/{id} cierre Volt form
- <hash> feat(tecnico): make dashboard cards tappable links to cierre form
- <hash> feat(tecnico): success flash on dashboard after cierre
- <hash> test(services): cover AsignacionCierreService 6 cases
- <hash> test(tecnico): cover PWA cierre flow 6 cases incl. security boundary

### Tests
- Suite total: 169 → ~181 verde.
- 4 jobs CI verde.

### Smoke pendiente al merge
- Reactivar técnico smoke id=66 + crear asignación tipo=1 abierta + ejecutar 8 puntos del smoke real.

### Pivots realizados (si los hubo)
- ...

### Riesgos conocidos
- Photo upload pesado (>5MB) puede quedar limitado por php.ini upload_max_filesize default 2M en SiteGround. Verificar en Bloque 15.
- (otros)

### Deudas que NO se atacan en este bloque
- tecnico_id race condition (chip spawned).
- kebab dropdown clipping (chip spawned).
- PWA throttle message UX (chip spawned).
- PWA full SW + offline + iconos (Bloque 11c, planeado).
```
