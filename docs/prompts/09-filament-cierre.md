# Bloque 09 — Cierre de asignación (Action admin con form condicional + 4 tests obligatorios DoD)

> Copia el bloque BEGIN PROMPT … END PROMPT en Copilot. ~90-120 min. **Sesión dedicada — bloque crítico**.

---

## Objetivo

Implementar la Action "Cerrar asignación" en `AsignacionResource` para que el admin pueda cerrar formalmente las asignaciones (creando el row correspondiente en `correctivo` o `revision`, subiendo fotos, marcando status=2). El form es condicional según `asignacion.tipo`:

- **`tipo=1` (correctivo / avería real)**: form con `diagnostico`, `recambios`, `estado_final`, `tiempo`, multi-upload de fotos → `lv_correctivo_imagen`. Flags facturación visibles solo aquí (admin-only).
- **`tipo=2` (revisión rutinaria)**: form con 7 checks OK/KO/N/A (`aspecto`, `funcionamiento`, `actuacion`, `audio`, `lineas`, `fecha_hora`, `precision_paso`) + `fecha` + `ruta` + `notas` (opcional, NUNCA autofilled).

**Field mapping correcto según ADR-0006** (verificado contra schema prod):
- `correctivo` tiene SOLO: `correctivo_id`, `tecnico_id`, `asignacion_id`, `tiempo` (varchar 45), `contrato`+`facturar_*` (4 tinyint), `recambios` (255), `diagnostico` (255), `estado_final` (100).
- **NO existen** las columnas `correctivo.accion`, `.fecha`, `.imagen`, `.notas`. Si Copilot intenta crearlas → MySQL rechaza → debug perdido. ADR-0006 lo previó.
- `revision` tiene `revision_id`, `tecnico_id`, `asignacion_id`, `fecha`, `ruta`, 7 checks (cada varchar 100), `fecha_hora`, `notas`.
- `lv_correctivo_imagen` (tabla NUEVA, Bloque 04): `id`, `correctivo_id`, `url`, `posicion`, timestamps.

**Tests obligatorios DoD del proyecto** (copilot-instructions.md):
1. `tipo_1_writes_correctivo_columns_not_notas` — cierre tipo=1 escribe a `correctivo.diagnostico/recambios/estado_final/tiempo`, NUNCA a `correctivo.notas` (la columna no existe).
2. `tipo_1_does_not_modify_averia_notas` — el técnico NO sobrescribe `averia.notas` (la rellena el operador, regla #3 + ADR-0004).
3. `tipo_2_writes_to_revision_only` — cierre tipo=2 escribe en `revision`, NUNCA en `correctivo`.
4. `tipo_2_notas_never_autofilled_with_revision_mensual` — `revision.notas` nunca se rellena automáticamente con "REVISION MENSUAL" ni variante. Si el admin no escribe nada, queda NULL/vacío.

## Estado pre-bloque (verificado)

- 1 asignación abierta en prod (`asignacion_id=32439`, `tipo=1`, `tecnico_id=40`, `averia_id=32507`) — target ideal para smoke real sin riesgo de cerrar prod accidentalmente.
- 66.415 cerradas (status=2). Histórico no se toca.
- 186 tipo=1 sin correctivo + 46.097 tipo=2 sin revision (legacy nunca creó rows formales) — backfill diferido a bloque futuro si se necesita.
- `public/storage` symlink **no existe** localmente → fase 0 lo crea.
- Default disk es `local` (privado). Vamos a usar `public` disk explícitamente para las fotos.

---

## Definition of Done

1. `php artisan storage:link` ejecutado → `public/storage` accesible públicamente para servir fotos.
2. `AsignacionResource` con Action "Cerrar" que abre slideOver con form condicional según `record.tipo`. Visible solo cuando `record.status != 2`.
3. Form **tipo=1** schema: `diagnostico`, `recambios`, `estado_final`, `tiempo`, FileUpload `imagenes` (multi, max 10, public disk, path `piv-images/correctivo`), Section "Facturación" con `contrato`+`facturar_horas`+`facturar_desplazamiento`+`facturar_recambios` toggles.
4. Form **tipo=2** schema: `fecha` (DatePicker), `ruta` (TextInput), 7 Selects OK/KO/N/A para los checks, `notas` Textarea **placeholder vacío** (jamás default "REVISION MENSUAL").
5. **Save handler transaccional**:
   - DB::transaction wrapping todo.
   - Si `tipo=1`: crear Correctivo + foreach foto crear LvCorrectivoImagen.
   - Si `tipo=2`: crear Revision.
   - `tecnico_id` se toma de `$record->tecnico_id` (la asignación), NO de `auth()->id()` (admin que cierra ≠ técnico que ejecutó).
   - Set `asignacion.status = 2`.
   - **NUNCA** modificar `averia.notas`.
   - Idempotente: si ya existe correctivo o revision para esta asignación, validation error "Esta asignación ya tiene cierre".
6. **9 tests Pest** (4 obligatorios DoD + 5 estructurales):
   - `tipo_1_writes_correctivo_columns_not_notas`
   - `tipo_1_does_not_modify_averia_notas`
   - `tipo_2_writes_to_revision_only`
   - `tipo_2_notas_never_autofilled_with_revision_mensual`
   - `tipo_1_creates_lv_correctivo_imagen_row_per_photo`
   - `tipo_1_does_not_attempt_to_write_correctivo_accion_or_imagen` (defensive contra ADR-0006 confusion)
   - `cerrar_action_hidden_when_status_is_2`
   - `cerrar_action_visible_when_status_is_open`
   - `cierre_uses_asignacion_tecnico_id_not_admin_user_id`
7. **Smoke local fase 5**: arrancar server, curl /admin/asignaciones/{abierta}/edit con sesión, verificar 200. **Smoke navegador post-merge** explícito en REPORTE FINAL: probar el cierre real con `asignacion_id=32439` (la única abierta en prod).
8. `pint --test`, `pest`, `npm run build` verdes.
9. PR creado, CI 3/3 verde.

---

## Riesgos y mitigaciones (checklist aplicada)

### 1. Compatibility framework

- [x] **Filament 3 Action with form**: soportado vía `Tables\Actions\Action::make()->form([...])->action(fn ($record, $data) => ...)`. Verificado.
- [x] **Conditional form fields según record state**: `->visible(fn ($record) => $record->tipo == 1)` en cada field. Filament evalúa por record.
- [x] **FileUpload multi**: `Forms\Components\FileUpload::make('fotos')->multiple()->disk('public')->directory('piv-images/correctivo')`. Estándar.
- [x] **slideOver para Action**: `->slideOver()->modalWidth('xl')`. Estándar.

### 2. Inferir de la app vieja

- [x] App vieja `winfin.es/calendar.php` muestra el form de cierre cuando admin/técnico click en una asignación pendiente. Estructura observada: 7 checks tipo=2 OK/KO/N/A en select; tipo=1 form con diagnostico + acción (= recambios en nuestro mapping ADR-0006) + estado + tiempo + foto upload.
- [x] El "REVISION MENSUAL Y OK" en notas era un BUG documentado del legacy (ADR-0004). Bloque 09 prohibe taxativamente autofill — `revision.notas` queda null/vacío si no se escribe.

### 3. Smoke real obligatorio en el prompt

- [x] Fase 5 incluye `php artisan serve` + curl al endpoint del admin con asignación abierta.
- [x] Smoke navegador requerido EXPLÍCITAMENTE en REPORTE FINAL — no opcional. El usuario debe probar con `asignacion_id=32439` (la única abierta) y confirmar que:
  - Action "Cerrar" aparece.
  - Form se abre con campos correctos para tipo=1.
  - Foto upload funciona (storage symlink debe estar OK).
  - Save crea Correctivo + LvCorrectivoImagen + status=2 atomicamente.
  - averia.notas NO se modificó.
  - Acción desaparece tras cerrar (status=2).

### 4. Test pivots de Copilot = banderazo rojo

- [x] Si Copilot debilita un test obligatorio o cambia las assertions del DoD, AVISA antes de seguir. Los 4 tests core son no negociables.

### 5. Datos prod-shaped, no factory-shaped

- [x] Test usa `Asignacion::factory()->create(['tipo' => 1, 'tecnico_id' => null])` para verificar que el cierre con tecnico_id null no rompe (caso edge real).
- [x] Test usa fotos con caracteres especiales en nombre (`foto con espacios.jpg`) para verificar que storage maneja paths bien.
- [x] Test verifica que el cast Latin1String en `correctivo.diagnostico` se aplica correctamente al guardar texto con tildes.

---

## El prompt para Copilot

```text
BEGIN PROMPT

Eres el agente Copilot del proyecto Winfin PIV. Lee primero:
- .github/copilot-instructions.md (incluye TODOS los patterns 08b/c/d/e/f/g/h y los tests obligatorios DoD del Bloque 09).
- DESIGN.md §10.1 (regla #11 separación tajante avería/revisión) + §10.2 (RGPD en exports).
- ARCHITECTURE.md §5.1 (schemas verificados) + §7 (flujos principales).
- docs/decisions/0004-revision-vs-averia-ux.md
- docs/decisions/0006-correctivo-schema-strategy.md (CRÍTICO — campos reales de correctivo).
- docs/prompts/09-filament-cierre.md (este archivo).
- app/Models/Asignacion.php, app/Models/Correctivo.php, app/Models/Revision.php, app/Models/LvCorrectivoImagen.php (modelos a usar).
- app/Filament/Resources/AsignacionResource.php (a extender con la Action).

Tu tarea: implementar Bloque 09 — Action "Cerrar" en AsignacionResource con form condicional tipo=1/tipo=2, save transaccional, file upload a lv_correctivo_imagen, 9 tests (4 obligatorios DoD + 5 estructurales).

**Bloque crítico de integridad legacy**. Los 4 tests DoD protegen reglas de negocio establecidas. NO debilitar tests ni saltar fases. Si Filament tiene un quirk con conditional fields o file upload, AVISA antes de pivotar.

Sigue las fases. PARA y AVISA tras cada una.

## FASE 0 — Pre-flight + branch + storage:link

```bash
pwd
git branch --show-current        # main
git rev-parse HEAD               # debe ser 4487603 (post Bloque 08h)
git status --short               # vacío
./vendor/bin/pest --colors=never --compact 2>&1 | tail -3
```

126 tests verdes esperados.

```bash
# Storage symlink — necesario para servir las fotos públicamente
php artisan storage:link
ls -la public/storage  # debe ser symlink a storage/app/public
```

```bash
git checkout -b bloque-09-filament-cierre
```

PARA: "Branch creada + storage:link OK. ¿Procedo a Fase 1 (Action skeleton + form schema)?"

## FASE 1 — Action "Cerrar" en AsignacionResource (form schema)

Lee `app/Filament/Resources/AsignacionResource.php`. Localiza el método `table()`. En el array `->actions([])`, AÑADE como PRIMERA action (antes de ViewAction):

```php
Tables\Actions\Action::make('cerrar')
    ->label('Cerrar asignación')
    ->icon('heroicon-o-check-badge')
    ->color('primary')
    ->visible(fn (Asignacion $record) => (int) $record->status !== 2)
    ->slideOver()
    ->modalWidth('xl')
    ->modalHeading(fn (Asignacion $record) => 'Cerrar '.((int) $record->tipo === 1 ? 'correctivo' : 'revisión rutinaria').' #'.$record->asignacion_id)
    ->form(fn (Asignacion $record) => self::cierreFormSchema($record))
    ->action(function (Asignacion $record, array $data): void {
        self::handleCierre($record, $data);
    }),
```

Después de `getPages()`, AÑADE el método estático `cierreFormSchema()`:

```php
private static function cierreFormSchema(Asignacion $record): array
{
    if ((int) $record->tipo === 1) {
        return self::cierreFormCorrectivo();
    }
    if ((int) $record->tipo === 2) {
        return self::cierreFormRevision();
    }
    // tipo=0 raro o desconocido — bloqueo por seguridad.
    return [
        Forms\Components\Placeholder::make('warning')
            ->label('')
            ->content('Asignación con tipo desconocido (tipo='.$record->tipo.'). No se puede cerrar desde aquí.'),
    ];
}

private static function cierreFormCorrectivo(): array
{
    return [
        Forms\Components\Section::make('Cierre correctivo (avería real)')
            ->description('Tipo=1: el técnico arregló una avería. Schema correctivo según ADR-0006: solo recambios/diagnostico/estado_final/tiempo + facturación. NO existen accion/imagen/fecha/notas en la tabla.')
            ->columns(2)
            ->schema([
                Forms\Components\Textarea::make('diagnostico')
                    ->label('Diagnóstico')
                    ->placeholder('Qué se diagnosticó como problema')
                    ->maxLength(255)
                    ->rows(2)
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('recambios')
                    ->label('Acción / Recambios')
                    ->placeholder('Qué se cambió o reparó')
                    ->maxLength(255)
                    ->rows(2)
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('estado_final')
                    ->label('Estado final')
                    ->placeholder('Ej. OK, Pendiente segunda visita')
                    ->maxLength(100)
                    ->default('OK'),
                Forms\Components\TextInput::make('tiempo')
                    ->label('Tiempo (horas decimales)')
                    ->placeholder('Ej. 0.5, 1.25')
                    ->maxLength(45),
            ]),

        Forms\Components\Section::make('Fotos del cierre')
            ->description('Fotos del panel reparado. Multi-upload, max 10. Se guardan en public/storage/piv-images/correctivo y se vinculan via lv_correctivo_imagen (ADR-0006).')
            ->schema([
                Forms\Components\FileUpload::make('fotos')
                    ->label('')
                    ->multiple()
                    ->maxFiles(10)
                    ->disk('public')
                    ->directory('piv-images/correctivo')
                    ->image()
                    ->reorderable()
                    ->openable()
                    ->downloadable()
                    ->columnSpanFull(),
            ]),

        Forms\Components\Section::make('Facturación (admin)')
            ->description('Flags admin-only. NO se exponen al técnico en su PWA (Bloque 11).')
            ->columns(4)
            ->collapsed()
            ->schema([
                Forms\Components\Toggle::make('contrato')->label('Contrato'),
                Forms\Components\Toggle::make('facturar_horas')->label('Facturar horas'),
                Forms\Components\Toggle::make('facturar_desplazamiento')->label('Facturar desplaz.'),
                Forms\Components\Toggle::make('facturar_recambios')->label('Facturar recambios'),
            ]),
    ];
}

private static function cierreFormRevision(): array
{
    $okKoNa = ['OK' => 'OK', 'KO' => 'KO', 'N/A' => 'N/A'];

    return [
        Forms\Components\Section::make('Cierre revisión rutinaria')
            ->description('Tipo=2: revisión mensual programada. NO es avería real (regla #11). Checklist OK/KO/N/A. Notas opcionales, NUNCA autofilled (bug histórico ADR-0004).')
            ->columns(2)
            ->schema([
                Forms\Components\DatePicker::make('fecha')->label('Fecha revisión')->default(now()),
                Forms\Components\TextInput::make('ruta')->label('Ruta')->maxLength(100),
                Forms\Components\TextInput::make('fecha_hora')->label('Verificación fecha/hora panel')->maxLength(100),
            ]),

        Forms\Components\Section::make('Checklist visual')
            ->columns(3)
            ->schema([
                Forms\Components\Select::make('aspecto')->options($okKoNa)->default('OK')->required(),
                Forms\Components\Select::make('funcionamiento')->options($okKoNa)->default('OK')->required(),
                Forms\Components\Select::make('actuacion')->options($okKoNa)->default('OK')->required(),
                Forms\Components\Select::make('audio')->options($okKoNa)->default('OK')->required(),
                Forms\Components\Select::make('lineas')->options($okKoNa)->default('OK')->required(),
                Forms\Components\Select::make('precision_paso')->label('Precisión paso')->options($okKoNa)->default('OK')->required(),
            ]),

        Forms\Components\Section::make('Notas')
            ->description('Opcional. SIN default, SIN autofill. ADR-0004 prohibe taxativamente "REVISION MENSUAL" como prefijo automático.')
            ->schema([
                Forms\Components\Textarea::make('notas')
                    ->label('')
                    ->placeholder('(opcional, escribe aquí cualquier observación)')
                    ->maxLength(100)
                    ->rows(2),
            ]),
    ];
}
```

NO escribir el `handleCierre` todavía — Fase 2.

PARA: "Fase 1 completa: Action + form schema condicional listos. ¿Procedo a Fase 2 (save handler transaccional)?"

## FASE 2 — Save handler `handleCierre`

Añade el método estático en AsignacionResource (después de `cierreFormRevision()`):

```php
private static function handleCierre(Asignacion $record, array $data): void
{
    \DB::transaction(function () use ($record, $data) {
        // Idempotencia — si ya existe cierre, abortar.
        if ((int) $record->tipo === 1 && $record->correctivo()->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'cerrar' => 'Esta asignación ya tiene un correctivo registrado.',
            ]);
        }
        if ((int) $record->tipo === 2 && $record->revision()->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'cerrar' => 'Esta asignación ya tiene una revisión registrada.',
            ]);
        }

        if ((int) $record->tipo === 1) {
            $correctivo = \App\Models\Correctivo::create([
                'tecnico_id' => $record->tecnico_id,    // del asignación, NO del admin
                'asignacion_id' => $record->asignacion_id,
                'tiempo' => $data['tiempo'] ?? null,
                'recambios' => $data['recambios'],
                'diagnostico' => $data['diagnostico'],
                'estado_final' => $data['estado_final'] ?? 'OK',
                'contrato' => $data['contrato'] ?? false,
                'facturar_horas' => $data['facturar_horas'] ?? false,
                'facturar_desplazamiento' => $data['facturar_desplazamiento'] ?? false,
                'facturar_recambios' => $data['facturar_recambios'] ?? false,
            ]);

            // Crear LvCorrectivoImagen para cada foto subida (ADR-0006).
            $fotos = $data['fotos'] ?? [];
            foreach ($fotos as $idx => $url) {
                \App\Models\LvCorrectivoImagen::create([
                    'correctivo_id' => $correctivo->correctivo_id,
                    'url' => $url,
                    'posicion' => $idx + 1,
                ]);
            }
        }

        if ((int) $record->tipo === 2) {
            \App\Models\Revision::create([
                'tecnico_id' => $record->tecnico_id,
                'asignacion_id' => $record->asignacion_id,
                'fecha' => $data['fecha']?->format('Y-m-d'),
                'ruta' => $data['ruta'] ?? null,
                'aspecto' => $data['aspecto'] ?? null,
                'funcionamiento' => $data['funcionamiento'] ?? null,
                'actuacion' => $data['actuacion'] ?? null,
                'audio' => $data['audio'] ?? null,
                'lineas' => $data['lineas'] ?? null,
                'fecha_hora' => $data['fecha_hora'] ?? null,
                'precision_paso' => $data['precision_paso'] ?? null,
                'notas' => $data['notas'] ?? null,    // NUNCA autofilled "REVISION MENSUAL"
            ]);
        }

        // Marcar asignación como cerrada. NO tocamos averia.notas (regla #3 + ADR-0004).
        $record->update(['status' => 2]);

        \Filament\Notifications\Notification::make()
            ->title('Cierre registrado')
            ->body('Asignación #'.$record->asignacion_id.' marcada como cerrada.')
            ->success()
            ->send();
    });
}
```

NOTA crítica: en NINGUNA línea aparece `averia->update()` ni `averia.notas`. Esto es la regla #3 + ADR-0004.

PARA: "Fase 2 completa: handleCierre transaccional listo. ¿Procedo a Fase 3 (tests obligatorios DoD)?"

## FASE 3 — Tests obligatorios DoD + estructurales

Añade a `tests/Feature/Filament/AsignacionResourceTest.php` (al final del archivo):

```php
use App\Filament\Resources\AsignacionResource;
use App\Models\Correctivo;
use App\Models\LvCorrectivoImagen;
use App\Models\Revision;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ---------- Tests obligatorios DoD (4) ----------

it('tipo_1_writes_correctivo_columns_not_notas', function () {
    $piv = Piv::factory()->create(['piv_id' => 91100]);
    $av = Averia::factory()->create(['averia_id' => 91100, 'piv_id' => 91100, 'notas' => 'Notas originales del operador']);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91100,
        'averia_id' => 91100,
        'tecnico_id' => 99,
        'tipo' => 1,
        'status' => 1,
    ]);

    Livewire::test(\App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'diagnostico' => 'Pantalla rota',
            'recambios' => 'Sustituida pantalla LCD',
            'estado_final' => 'OK',
            'tiempo' => '1.5',
            'fotos' => [],
        ]);

    $cor = Correctivo::where('asignacion_id', 91100)->first();
    expect($cor)->not->toBeNull();
    expect($cor->diagnostico)->toBe('Pantalla rota');
    expect($cor->recambios)->toBe('Sustituida pantalla LCD');
    expect($cor->estado_final)->toBe('OK');
    expect($cor->tiempo)->toBe('1.5');
    // El schema no tiene notas. Si Copilot intentó escribir ahí, MySQL falla → este test no llega aquí.
});

it('tipo_1_does_not_modify_averia_notas', function () {
    $piv = Piv::factory()->create(['piv_id' => 91101]);
    $av = Averia::factory()->create(['averia_id' => 91101, 'piv_id' => 91101, 'notas' => 'NOTAS ORIGINALES INTOCABLES']);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91101,
        'averia_id' => 91101,
        'tecnico_id' => 99,
        'tipo' => 1,
        'status' => 1,
    ]);

    Livewire::test(\App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'diagnostico' => 'X',
            'recambios' => 'Y',
            'estado_final' => 'OK',
            'tiempo' => '0.5',
            'fotos' => [],
        ]);

    expect($av->fresh()->notas)->toBe('NOTAS ORIGINALES INTOCABLES');
});

it('tipo_2_writes_to_revision_only', function () {
    $piv = Piv::factory()->create(['piv_id' => 91102]);
    $av = Averia::factory()->create(['averia_id' => 91102, 'piv_id' => 91102]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91102,
        'averia_id' => 91102,
        'tecnico_id' => 99,
        'tipo' => 2,
        'status' => 1,
    ]);

    Livewire::test(\App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'fecha' => now()->format('Y-m-d'),
            'ruta' => 'Ruta 1',
            'aspecto' => 'OK',
            'funcionamiento' => 'OK',
            'actuacion' => 'OK',
            'audio' => 'OK',
            'lineas' => 'OK',
            'fecha_hora' => 'OK',
            'precision_paso' => 'OK',
            'notas' => null,
        ]);

    expect(Revision::where('asignacion_id', 91102)->exists())->toBeTrue();
    expect(Correctivo::where('asignacion_id', 91102)->exists())->toBeFalse();
});

it('tipo_2_notas_never_autofilled_with_revision_mensual', function () {
    $piv = Piv::factory()->create(['piv_id' => 91103]);
    $av = Averia::factory()->create(['averia_id' => 91103, 'piv_id' => 91103]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91103,
        'averia_id' => 91103,
        'tecnico_id' => 99,
        'tipo' => 2,
        'status' => 1,
    ]);

    // Admin NO escribe nada en notas — debe quedar vacío, NUNCA autofill.
    Livewire::test(\App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'fecha' => now()->format('Y-m-d'),
            'aspecto' => 'OK',
            'funcionamiento' => 'OK',
            'actuacion' => 'OK',
            'audio' => 'OK',
            'lineas' => 'OK',
            'fecha_hora' => 'OK',
            'precision_paso' => 'OK',
        ]);

    $rev = Revision::where('asignacion_id', 91103)->first();
    expect($rev)->not->toBeNull();
    expect($rev->notas)->not->toContain('REVISION MENSUAL');
    expect($rev->notas)->not->toContain('REVISION');
    expect(empty(trim($rev->notas ?? '')))->toBeTrue('notas debe estar vacío si no se escribió');
});

// ---------- Tests estructurales (5) ----------

it('tipo_1_creates_lv_correctivo_imagen_row_per_photo', function () {
    Storage::fake('public');

    $piv = Piv::factory()->create(['piv_id' => 91110]);
    $av = Averia::factory()->create(['averia_id' => 91110, 'piv_id' => 91110]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91110,
        'averia_id' => 91110,
        'tecnico_id' => 99,
        'tipo' => 1,
        'status' => 1,
    ]);

    $foto1 = UploadedFile::fake()->image('foto1.jpg');
    $foto2 = UploadedFile::fake()->image('foto2.jpg');

    Livewire::test(\App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'diagnostico' => 'X',
            'recambios' => 'Y',
            'estado_final' => 'OK',
            'fotos' => [$foto1, $foto2],
        ]);

    $cor = Correctivo::where('asignacion_id', 91110)->first();
    expect($cor)->not->toBeNull();
    expect(LvCorrectivoImagen::where('correctivo_id', $cor->correctivo_id)->count())->toBe(2);
});

it('tipo_1_does_not_attempt_to_write_correctivo_accion_or_imagen', function () {
    // ADR-0006: la tabla correctivo NO TIENE accion ni imagen. Si el handler intenta
    // crear con esas keys, MySQL acepta (las ignora silenciosamente con $fillable
    // strict mode). Verificación defensiva: el row creado NO tiene esos atributos.
    $piv = Piv::factory()->create(['piv_id' => 91120]);
    $av = Averia::factory()->create(['averia_id' => 91120, 'piv_id' => 91120]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91120,
        'averia_id' => 91120,
        'tecnico_id' => 99,
        'tipo' => 1,
        'status' => 1,
    ]);

    Livewire::test(\App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'diagnostico' => 'X',
            'recambios' => 'Y',
            'estado_final' => 'OK',
            'fotos' => [],
        ]);

    $cor = Correctivo::where('asignacion_id', 91120)->first();
    expect($cor)->not->toBeNull();
    // ADR-0006 confirma estas columnas NO existen — el atributo no debe estar set.
    expect(isset($cor->accion))->toBeFalse('correctivo.accion no existe en schema');
    expect(isset($cor->imagen))->toBeFalse('correctivo.imagen no existe en schema');
    expect(isset($cor->fecha))->toBeFalse('correctivo.fecha no existe en schema');
});

it('cerrar_action_hidden_when_status_is_2', function () {
    $piv = Piv::factory()->create(['piv_id' => 91130]);
    $av = Averia::factory()->create(['averia_id' => 91130, 'piv_id' => 91130]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91130,
        'averia_id' => 91130,
        'tipo' => 1,
        'status' => 2,    // YA cerrada
    ]);

    Livewire::test(\App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones::class)
        ->assertTableActionHidden('cerrar', $asig);
});

it('cerrar_action_visible_when_status_is_open', function () {
    $piv = Piv::factory()->create(['piv_id' => 91131]);
    $av = Averia::factory()->create(['averia_id' => 91131, 'piv_id' => 91131]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91131,
        'averia_id' => 91131,
        'tipo' => 1,
        'status' => 1,
    ]);

    Livewire::test(\App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones::class)
        ->assertTableActionVisible('cerrar', $asig);
});

it('cierre_uses_asignacion_tecnico_id_not_admin_user_id', function () {
    $piv = Piv::factory()->create(['piv_id' => 91140]);
    $av = Averia::factory()->create(['averia_id' => 91140, 'piv_id' => 91140]);
    $asig = Asignacion::factory()->create([
        'asignacion_id' => 91140,
        'averia_id' => 91140,
        'tecnico_id' => 777,    // técnico originalmente asignado
        'tipo' => 1,
        'status' => 1,
    ]);

    // Admin (lv_users.id=1) cierra a nombre del técnico 777.
    expect($this->admin->id)->not->toBe(777);

    Livewire::test(\App\Filament\Resources\AsignacionResource\Pages\ListAsignaciones::class)
        ->callTableAction('cerrar', $asig->asignacion_id, data: [
            'diagnostico' => 'X',
            'recambios' => 'Y',
            'estado_final' => 'OK',
            'fotos' => [],
        ]);

    $cor = Correctivo::where('asignacion_id', 91140)->first();
    expect($cor->tecnico_id)->toBe(777);    // del asignación, NO admin
});
```

Corre tests:
```bash
./vendor/bin/pest tests/Feature/Filament/AsignacionResourceTest.php --colors=never --compact 2>&1 | tail -25
```

9 tests nuevos verdes esperados (4 obligatorios + 5 estructurales). Suite total ~135 verde.

Si algún test obligatorio falla, AVISA antes de tocar la handler. Es la línea roja del bloque.

PARA: "Fase 3 completa: 9 tests verdes incluyendo los 4 obligatorios DoD. ¿Procedo a Fase 4 (smoke local)?"

## FASE 4 — Smoke local

```bash
./vendor/bin/pint --test 2>&1 | tail -3
./vendor/bin/pest --colors=never --compact 2>&1 | tail -5
npm run build 2>&1 | tail -3

# Smoke endpoint con sesión hipotética:
php artisan serve --host=127.0.0.1 --port=8001 &
SERVER_PID=$!
sleep 2

curl -sI -o /dev/null -w "GET /admin/asignaciones -> HTTP %{http_code}\n" http://127.0.0.1:8001/admin/asignaciones
curl -sI -o /dev/null -w "GET /admin/asignaciones/32439/edit -> HTTP %{http_code}\n" http://127.0.0.1:8001/admin/asignaciones/32439/edit

kill $SERVER_PID 2>/dev/null
```

302 esperado (redirect a login sin sesión) — confirma que las rutas NO crashean sin sesión.

PARA: "Fase 4 completa: smoke local OK. ¿Procedo a Fase 5 (commits + PR)?"

## FASE 5 — Pint + commits + PR

Stage explícito:

1. `docs: add Bloque 09 prompt (cierre asignación)` — `docs/prompts/09-filament-cierre.md`.
2. `feat(filament): add cerrar action to AsignacionResource with conditional form` — `app/Filament/Resources/AsignacionResource.php`.
3. `test: cover Bloque 09 DoD obligatory tests + structural` — `tests/Feature/Filament/AsignacionResourceTest.php`.

Push + PR:

```bash
git push -u origin bloque-09-filament-cierre
gh pr create --base main --head bloque-09-filament-cierre \
  --title "Bloque 09 — Cierre asignación (Action admin con form condicional + 9 tests)" \
  --body "$(cat <<'BODY'
## Resumen

Implementa la Action 'Cerrar asignación' en AsignacionResource con form condicional según tipo (1=correctivo / 2=revisión). Save transaccional crea Correctivo o Revision + LvCorrectivoImagen para fotos. Status pasa a 2.

**4 tests obligatorios DoD del proyecto** todos verdes:

- tipo_1_writes_correctivo_columns_not_notas
- tipo_1_does_not_modify_averia_notas
- tipo_2_writes_to_revision_only
- tipo_2_notas_never_autofilled_with_revision_mensual

Plus 5 estructurales:

- tipo_1_creates_lv_correctivo_imagen_row_per_photo
- tipo_1_does_not_attempt_to_write_correctivo_accion_or_imagen
- cerrar_action_hidden_when_status_is_2
- cerrar_action_visible_when_status_is_open
- cierre_uses_asignacion_tecnico_id_not_admin_user_id

## Decisiones clave

- Action visible solo cuando status != 2.
- Form condicional: tipo=1 muestra schema correctivo (diagnostico/recambios/estado_final/tiempo + facturación + fotos multi-upload). tipo=2 muestra checklist OK/KO/N/A 7 fields + fecha + ruta + notas.
- ADR-0006 respetado: NUNCA se intenta escribir correctivo.accion/imagen/fecha (no existen).
- ADR-0004 respetado: revision.notas SIN default ni autofill 'REVISION MENSUAL'.
- Regla #3 RGPD respetada: averia.notas NO se modifica al cerrar.
- tecnico_id se copia de asignación, NO del admin que cierra.
- Save transaccional con idempotencia (rechaza si ya existe correctivo/revision).
- Fotos a public disk path piv-images/correctivo. lv_correctivo_imagen rows por foto (ADR-0006).

## Smoke real obligatorio post-merge

Ejecutar con la única asignación abierta en prod (asignacion_id=32439, tipo=1, tecnico_id=40):

1. php artisan serve
2. /admin/asignaciones -> filter por status=1 -> aparece la 32439.
3. Click action 'Cerrar' -> abre slideOver con form correctivo.
4. Rellenar diagnostico/recambios/estado_final/tiempo + opcionalmente subir foto.
5. Save -> verificar:
   - Notification 'Cierre registrado'.
   - Correctivo creado con tecnico_id=40.
   - LvCorrectivoImagen creada por foto (si aplica).
   - asignacion 32439.status pasó a 2.
   - averia 32507.notas SIN cambios.
6. Refresh listing -> action 'Cerrar' YA NO aparece para 32439.

## CI esperado

3/3 verde.
BODY
)"

sleep 8
PR_NUM=$(gh pr list --head bloque-09-filament-cierre --json number --jq '.[0].number')
gh pr view $PR_NUM --json url --jq '.url'
gh pr checks $PR_NUM --watch
```

## REPORTE FINAL

```
✅ Qué he hecho:
   - storage:link OK.
   - AsignacionResource Action 'cerrar' con form condicional tipo=1/tipo=2.
   - Save transaccional handleCierre + idempotencia + LvCorrectivoImagen.
   - 9 tests verdes (4 obligatorios DoD + 5 estructurales).
   - tecnico_id de asignación, NUNCA averia.notas, NUNCA revision.notas autofill.
   - Pint + build OK.
   - 3 commits.
   - PR #N. CI 3/3 verde.

⏳ Smoke real obligatorio post-merge (DoD #11 del checklist):
   - php artisan serve
   - Login info@winfin.es
   - /admin/asignaciones -> filter status=1 -> 32439
   - Click 'Cerrar' -> rellenar form correctivo -> Save
   - Verificar Correctivo + LvCorrectivoImagen + status=2 + averia.notas intacto.
```

NO mergees.

END PROMPT
```

---

## Después de Bloque 09

1. Smoke real con `asignacion_id=32439` validando los 4 tests obligatorios DoD desde la UI.
2. **Bloque 10 — Dashboard + exports** con `TecnicoExportTransformer` (RGPD blacklist) + KPIs widgets + reincorporación de "Reportes" en sidebar (Bloque 08d/g referenció esto).
3. Alternativa: **Bloque 11 — PWA técnico** (donde la Action de cierre se expone al técnico en móvil sin las flags de facturación admin-only).
4. **Bloque 02c** parche calendar.php sigue pendiente, baja prioridad ahora que el sistema nuevo cubre el cierre correctamente.
