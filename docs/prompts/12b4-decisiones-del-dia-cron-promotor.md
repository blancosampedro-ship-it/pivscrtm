# Bloque 12b.4 — UI admin "Decisiones del día" + cron daily promotor a `asignacion`

## Contexto

Tras Bloque 12b.3 (PR #36 + smoke real prod 4 may tarde) la BD prod tiene 484 filas en
`lv_revision_pendiente` para mayo 2026, todas en status `pendiente`. Falta la pieza que
permite al admin **decidir cada panel día a día**: verificarlo remoto / programar visita
física / marcar excepción.

Este bloque entrega:
1. **UI Filament admin** para revisar y decidir cada fila (resource + 4 actions + bulk).
2. **Cron daily 06:00 Europe/Madrid** que promueve a `asignacion` legacy las filas con
   `status=requiere_visita` y `fecha_planificada == today`.
3. **Hook ya existente** desde Bloque 12b.3: cuando técnico cierra la asignación via
   PWA, `AsignacionCierreService::cerrar()` marca `lv_revision_pendiente.status =
   completada`. El loop se cierra solo.

Bloque 12b.5 (futuro) entrega calendario operacional admin (vista mensual + drag-drop).
Bloque 12b.6 (futuro) entrega reporte mensual contractual.

## Decisiones cerradas con el usuario antes del prompt (8 puntos)

Confirmadas vía OK explícito (4 may tarde). Resumen literal:

1. **Estructura UI**: `LvRevisionPendienteResource` estándar Filament + nuevo grupo sidebar **"Planificación"**. NO página custom (eso es 12b.5).
2. **3 actions inline por fila** (visibles solo si `status='pendiente'`): `verificarRemoto` (success, modal notas opcional), `requiereVisita` (warning, modal date picker mín=today + notas opcional), `marcarExcepcion` (danger, modal notas REQUIRED). 4ª action `revertir` (visible si status≠pendiente y status≠completada): limpia decision_* y vuelve a pendiente.
3. **Bulk action** solo `verificarRemoto`. Confirmación obligatoria. Filas en status≠pendiente se ignoran silenciosamente.
4. **Filtros** tabla: Zona (select join `lv_piv_zona`), Status, Carry-over (Ternary), Fecha planificada (date), Mes/año (default = current month).
5. **Default sort**: `carry_over_origen_id DESC NULLS LAST` (carry overs arriba) → `status` (pendientes primero) → `piv_id`.
6. **Badge carry-over**: si `carry_over_origen_id` no null, badge pequeño + tooltip "Pendiente desde mes X (status Y)".
7. **Cron daily** `lv:promote-revisiones-to-asignacion` schedule `dailyAt('06:00')->timezone('Europe/Madrid')->onOneServer()`. Crea `averia` stub + `asignacion` legacy `tipo=2 status=1`, **`tecnico_id` NULL** (admin lo asigna posteriormente desde `AsignacionResource`). Idempotente por filter `asignacion_id IS NULL`.
8. **Header action "Promover ahora"** en la tabla — ejecuta el cron manual para `today`. Útil si admin marca "requiere visita today" después de las 06:00.

## Restricciones inviolables

- **NO modificar tablas legacy** (`piv`, `modulo`, `tecnico`, `operador`, `correctivo`,
  `revision`, `instalacion*`, etc.). Solo INSERT en `averia` y `asignacion` (cumplen
  ADR-0001 + ADR-0004 — averías-stub para revisión rutinaria es el patrón legacy
  documentado).
- **NO tocar el modelo `LvRevisionPendiente`** salvo añadir scopes/helpers nuevos sin
  cambiar columnas (la migration 12b.3 ya está aplicada en prod).
- **NO cron daily promotor en horario distinto a 06:00 Europe/Madrid** salvo OK
  explícito del usuario. Cuadre con cron mensual 12b.3.
- **NO asignar `tecnico_id` automático**. Queda NULL en `asignacion` legacy. Admin lo
  asigna luego desde `AsignacionResource` (sidebar Operaciones → Asignaciones).
- **DESIGN.md Carbon obligatorio** — sin novedades estéticas. Slug explícito en el
  Resource para evitar pluralizer-bug del Bloque 08b. `$infolist` (no `$i`) en cualquier
  closure que reciba Infolist (Bloque 08c). NO RelationManager en ViewRecord (Bloque
  08g/h) — usar partial Blade si hace falta drill-in.
- **Tests Pest verde obligatorio**. Suite actual 263 → ~287 verde tras este bloque.
- **CI 3/3 verde**.
- **Pint clean**.
- **PHP 8.2 floor**.
- **Cero paquete nuevo** en composer.json.
- **Cero migration nueva** (todo el schema necesario ya existe).

## Plan de cambios

### Step 1 — Helper `LvRevisionPendiente::scopeNoPromocionadas()`

Añadir al modelo `app/Models/LvRevisionPendiente.php` (sin tocar lo existente):

```php
public function scopeNoPromocionadas(Builder $query): void
{
    $query->whereNull('asignacion_id');
}

public function scopeRequiereVisitaParaFecha(Builder $query, \DateTimeInterface $date): void
{
    $query->where('status', self::STATUS_REQUIERE_VISITA)
        ->whereDate('fecha_planificada', $date->format('Y-m-d'));
}
```

Tests añadidos a `LvRevisionPendienteSchemaTest.php`:
- `scope noPromocionadas filtra solo asignacion_id null`.
- `scope requiereVisitaParaFecha filtra por status y fecha`.

### Step 2 — Service `App\Services\RevisionPendientePromotorService`

`app/Services/RevisionPendientePromotorService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\LvRevisionPendiente;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Promueve filas lv_revision_pendiente con status=requiere_visita y
 * fecha_planificada == $date a asignacion legacy tipo=2 status=1.
 *
 * Llamado por cron daily 06:00 Europe/Madrid + manualmente por header
 * action "Promover ahora" en LvRevisionPendienteResource.
 *
 * Idempotente por filter asignacion_id IS NULL.
 */
final class RevisionPendientePromotorService
{
    public const NOTAS_AVERIA_STUB = 'Revisión preventiva mensual';

    /**
     * @return array{promoted: int, skipped: int}
     */
    public function promoverDelDia(?DateTimeInterface $date = null): array
    {
        $target = CarbonImmutable::instance($date ?? now('Europe/Madrid'))
            ->setTimezone('Europe/Madrid')
            ->startOfDay();

        $promoted = 0;
        $skipped = 0;

        return DB::transaction(function () use ($target, &$promoted, &$skipped): array {
            LvRevisionPendiente::query()
                ->requiereVisitaParaFecha($target)
                ->noPromocionadas()
                ->cursor()
                ->each(function (LvRevisionPendiente $row) use ($target, &$promoted, &$skipped): void {
                    $averia = Averia::create([
                        'piv_id' => $row->piv_id,
                        'notas' => self::NOTAS_AVERIA_STUB,
                        'status' => 1,
                        // operador_id, tecnico_id, fecha (default CURRENT_TIMESTAMP) NULL.
                    ]);

                    $asignacion = Asignacion::create([
                        'averia_id' => $averia->averia_id,
                        'tipo' => Asignacion::TIPO_REVISION,
                        'fecha' => $target->format('Y-m-d'),
                        'status' => 1,
                        // tecnico_id NULL — admin lo asignará desde AsignacionResource.
                    ]);

                    $row->update(['asignacion_id' => $asignacion->asignacion_id]);

                    $promoted++;
                });

            return ['promoted' => $promoted, 'skipped' => $skipped];
        });
    }
}
```

**Notas**:
- `Asignacion::TIPO_REVISION = 2` (constante existente desde Bloque 09).
- `Averia::create()` con `fecha` omitida → MySQL default `CURRENT_TIMESTAMP`.
- `tecnico_id` NULL en ambos. Admin asigna posteriormente desde sidebar Operaciones → Asignaciones.
- Transacción wrapping garantiza atomicity (averia + asignacion + lv_rev update juntas).
- `cursor()` para no cargar todas las filas en memoria (aunque típicamente solo 1-30/día).
- `skipped` no incrementa en este flujo porque el filtro `noPromocionadas` ya excluye las que tienen `asignacion_id`. Mantengo el campo en el return para coherencia con el reporte del cron.

### Step 3 — Console command `lv:promote-revisiones-to-asignacion`

`app/Console/Commands/PromoteRevisionesToAsignacion.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RevisionPendientePromotorService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class PromoteRevisionesToAsignacion extends Command
{
    protected $signature = 'lv:promote-revisiones-to-asignacion
                            {--date= : Fecha YYYY-MM-DD (default = today Europe/Madrid)}';

    protected $description = 'Promueve lv_revision_pendiente requiere_visita+fecha=$date a asignacion legacy. Idempotente.';

    public function handle(RevisionPendientePromotorService $svc): int
    {
        $dateOpt = $this->option('date');

        try {
            $target = $dateOpt
                ? CarbonImmutable::createFromFormat('Y-m-d', $dateOpt, 'Europe/Madrid')->startOfDay()
                : CarbonImmutable::now('Europe/Madrid')->startOfDay();
        } catch (\Throwable $e) {
            $this->error("Fecha inválida: {$dateOpt}. Formato esperado YYYY-MM-DD.");
            return self::INVALID;
        }

        if ($target === false) {
            $this->error("Fecha inválida: {$dateOpt}.");
            return self::INVALID;
        }

        $this->info("Promoviendo lv_revision_pendiente para {$target->format('Y-m-d')}...");

        $result = $svc->promoverDelDia($target);

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['promoted (filas → asignacion)', $result['promoted']],
                ['skipped (ya promocionadas)', $result['skipped']],
            ],
        );

        return self::SUCCESS;
    }
}
```

### Step 4 — Schedule en `routes/console.php`

Añadir AL FINAL (después del bloque mensual ya existente):

```php
Schedule::command('lv:promote-revisiones-to-asignacion')
    ->dailyAt('06:00')
    ->timezone('Europe/Madrid')
    ->onOneServer()
    ->name('lv-promote-revisiones-to-asignacion');
```

**Nota**: ambos crons (mensual día 1 06:00 + daily 06:00) coinciden hora. El día 1 del mes ambos ejecutan; el orden no importa (mensual genera filas pendientes nuevas; daily promueve solo `requiere_visita` que admin haya marcado del día anterior — el día 1 no hay nada que promover).

### Step 5 — Filament Resource `LvRevisionPendienteResource`

`app/Filament/Resources/LvRevisionPendienteResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LvRevisionPendienteResource\Pages;
use App\Models\LvPivZona;
use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use App\Services\RevisionPendientePromotorService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class LvRevisionPendienteResource extends Resource
{
    protected static ?string $model = LvRevisionPendiente::class;

    protected static ?string $slug = 'revisiones-pendientes';

    protected static ?string $navigationLabel = 'Decisiones del día';

    protected static ?string $modelLabel = 'Revisión pendiente';

    protected static ?string $pluralModelLabel = 'Revisiones pendientes';

    protected static ?string $navigationGroup = 'Planificación';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    public static function getNavigationBadge(): ?string
    {
        $today = CarbonImmutable::now('Europe/Madrid')->startOfDay();
        $count = LvRevisionPendiente::query()
            ->where('status', LvRevisionPendiente::STATUS_PENDIENTE)
            ->where('periodo_year', $today->year)
            ->where('periodo_month', $today->month)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        $now = CarbonImmutable::now('Europe/Madrid');

        return $table
            ->modifyQueryUsing(fn (Builder $q) => $q->with(['piv', 'decisionUser', 'carryOverOrigen']))
            ->defaultSort(fn (Builder $q) => $q
                ->orderByRaw('carry_over_origen_id IS NULL ASC')
                ->orderByRaw("FIELD(status, 'pendiente', 'requiere_visita', 'excepcion', 'verificada_remoto', 'completada')")
                ->orderBy('piv_id'))
            ->columns([
                TextColumn::make('carry_badge')
                    ->label('')
                    ->state(fn (LvRevisionPendiente $r) => $r->isCarryOver() ? 'Carry' : null)
                    ->badge()
                    ->color('warning')
                    ->tooltip(fn (LvRevisionPendiente $r) => $r->isCarryOver()
                        ? "Pendiente desde periodo {$r->carryOverOrigen?->periodo_year}-{$r->carryOverOrigen?->periodo_month} (status: {$r->carryOverOrigen?->status})"
                        : null),

                TextColumn::make('piv.parada_cod')
                    ->label('Panel')
                    ->getStateUsing(fn (LvRevisionPendiente $r) => $r->piv?->parada_cod ?? '—')
                    ->description(fn (LvRevisionPendiente $r) => "ID {$r->piv_id}")
                    ->searchable(query: fn (Builder $q, string $search) => $q
                        ->whereHas('piv', fn (Builder $q2) => $q2->where('parada_cod', 'like', "%{$search}%"))),

                TextColumn::make('piv.municipio_nombre')
                    ->label('Zona')
                    ->getStateUsing(function (LvRevisionPendiente $r): string {
                        $municipioId = (int) ($r->piv?->municipio ?? 0);
                        if ($municipioId === 0) return '—';
                        $zonaName = DB::table('lv_piv_zona_municipio')
                            ->join('lv_piv_zona', 'lv_piv_zona.id', '=', 'lv_piv_zona_municipio.zona_id')
                            ->where('lv_piv_zona_municipio.municipio_modulo_id', $municipioId)
                            ->value('lv_piv_zona.nombre');
                        return $zonaName ?? '—';
                    }),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        LvRevisionPendiente::STATUS_PENDIENTE => 'gray',
                        LvRevisionPendiente::STATUS_VERIFICADA_REMOTO => 'success',
                        LvRevisionPendiente::STATUS_REQUIERE_VISITA => 'warning',
                        LvRevisionPendiente::STATUS_EXCEPCION => 'danger',
                        LvRevisionPendiente::STATUS_COMPLETADA => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('fecha_planificada')
                    ->label('Fecha')
                    ->date('Y-m-d')
                    ->placeholder('—'),

                TextColumn::make('decisionUser.name')
                    ->label('Decidido por')
                    ->getStateUsing(fn (LvRevisionPendiente $r) => $r->decisionUser?->name ?? '—'),

                TextColumn::make('decision_notas')
                    ->label('Notas')
                    ->limit(40)
                    ->tooltip(fn (LvRevisionPendiente $r) => $r->decision_notas),
            ])
            ->filters([
                SelectFilter::make('zona')
                    ->label('Zona')
                    ->options(fn () => LvPivZona::query()->orderBy('sort_order')->pluck('nombre', 'id')->toArray())
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) return $query;
                        return $query->whereExists(function ($sub) use ($data) {
                            $sub->select(DB::raw(1))
                                ->from('lv_piv_zona_municipio AS zm')
                                ->join('piv', 'piv.municipio', '=', DB::raw('zm.municipio_modulo_id'))
                                ->whereColumn('piv.piv_id', 'lv_revision_pendiente.piv_id')
                                ->where('zm.zona_id', $data['value']);
                        });
                    }),

                SelectFilter::make('status')
                    ->options([
                        LvRevisionPendiente::STATUS_PENDIENTE => 'Pendiente',
                        LvRevisionPendiente::STATUS_VERIFICADA_REMOTO => 'Verificada remoto',
                        LvRevisionPendiente::STATUS_REQUIERE_VISITA => 'Requiere visita',
                        LvRevisionPendiente::STATUS_EXCEPCION => 'Excepción',
                        LvRevisionPendiente::STATUS_COMPLETADA => 'Completada',
                    ]),

                TernaryFilter::make('carry_over')
                    ->label('Carry-over')
                    ->placeholder('Todos')
                    ->trueLabel('Solo carry overs')
                    ->falseLabel('Sin carry')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('carry_over_origen_id'),
                        false: fn (Builder $q) => $q->whereNull('carry_over_origen_id'),
                    ),

                Filter::make('fecha_planificada')
                    ->form([DatePicker::make('fecha_planificada')->label('Fecha planificada')])
                    ->query(fn (Builder $q, array $data) => isset($data['fecha_planificada'])
                        ? $q->whereDate('fecha_planificada', $data['fecha_planificada'])
                        : $q),

                Filter::make('mes')
                    ->default()
                    ->form([
                        \Filament\Forms\Components\Select::make('periodo_year')->options(self::yearOptions())->default($now->year),
                        \Filament\Forms\Components\Select::make('periodo_month')->options(self::monthOptions())->default($now->month),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['periodo_year'] ?? null, fn (Builder $q, $y) => $q->where('periodo_year', $y))
                        ->when($data['periodo_month'] ?? null, fn (Builder $q, $m) => $q->where('periodo_month', $m))),
            ])
            ->actions([
                Action::make('verificarRemoto')
                    ->label('Verificar remoto')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (LvRevisionPendiente $r) => $r->status === LvRevisionPendiente::STATUS_PENDIENTE)
                    ->form([Textarea::make('decision_notas')->label('Notas (opcional)')->maxLength(2000)])
                    ->action(function (LvRevisionPendiente $r, array $data): void {
                        $r->update([
                            'status' => LvRevisionPendiente::STATUS_VERIFICADA_REMOTO,
                            'decision_user_id' => auth()->id(),
                            'decision_at' => now(),
                            'decision_notas' => $data['decision_notas'] ?? null,
                        ]);
                        Notification::make()->title('Marcada como verificada remoto')->success()->send();
                    }),

                Action::make('requiereVisita')
                    ->label('Requiere visita')
                    ->icon('heroicon-o-truck')
                    ->color('warning')
                    ->visible(fn (LvRevisionPendiente $r) => $r->status === LvRevisionPendiente::STATUS_PENDIENTE)
                    ->form([
                        DatePicker::make('fecha_planificada')
                            ->label('Fecha de visita')
                            ->required()
                            ->minDate(today('Europe/Madrid'))
                            ->default(today('Europe/Madrid')),
                        Textarea::make('decision_notas')->label('Notas (opcional)')->maxLength(2000),
                    ])
                    ->action(function (LvRevisionPendiente $r, array $data): void {
                        $r->update([
                            'status' => LvRevisionPendiente::STATUS_REQUIERE_VISITA,
                            'fecha_planificada' => $data['fecha_planificada'],
                            'decision_user_id' => auth()->id(),
                            'decision_at' => now(),
                            'decision_notas' => $data['decision_notas'] ?? null,
                        ]);
                        Notification::make()->title('Programada para visita')->success()->send();
                    }),

                Action::make('marcarExcepcion')
                    ->label('Excepción')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (LvRevisionPendiente $r) => $r->status === LvRevisionPendiente::STATUS_PENDIENTE)
                    ->form([
                        Textarea::make('decision_notas')
                            ->label('Motivo de la excepción')
                            ->required()
                            ->maxLength(2000),
                    ])
                    ->action(function (LvRevisionPendiente $r, array $data): void {
                        $r->update([
                            'status' => LvRevisionPendiente::STATUS_EXCEPCION,
                            'decision_user_id' => auth()->id(),
                            'decision_at' => now(),
                            'decision_notas' => $data['decision_notas'],
                        ]);
                        Notification::make()->title('Marcada como excepción')->warning()->send();
                    }),

                Action::make('revertir')
                    ->label('Revertir decisión')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (LvRevisionPendiente $r) => ! in_array($r->status, [
                        LvRevisionPendiente::STATUS_PENDIENTE,
                        LvRevisionPendiente::STATUS_COMPLETADA,
                    ], true) && $r->asignacion_id === null)
                    ->requiresConfirmation()
                    ->action(function (LvRevisionPendiente $r): void {
                        $r->update([
                            'status' => LvRevisionPendiente::STATUS_PENDIENTE,
                            'fecha_planificada' => null,
                            'decision_user_id' => null,
                            'decision_at' => null,
                            'decision_notas' => null,
                        ]);
                        Notification::make()->title('Decisión revertida')->success()->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('verificarRemotoBulk')
                    ->label('Verificar remoto (bulk)')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->form([Textarea::make('decision_notas')->label('Notas (aplicará a todas)')->maxLength(2000)])
                    ->action(function (Collection $records, array $data): void {
                        $userId = auth()->id();
                        $now = now();
                        $count = 0;
                        DB::transaction(function () use ($records, $data, $userId, $now, &$count): void {
                            foreach ($records as $r) {
                                if ($r->status !== LvRevisionPendiente::STATUS_PENDIENTE) {
                                    continue;
                                }
                                $r->update([
                                    'status' => LvRevisionPendiente::STATUS_VERIFICADA_REMOTO,
                                    'decision_user_id' => $userId,
                                    'decision_at' => $now,
                                    'decision_notas' => $data['decision_notas'] ?? null,
                                ]);
                                $count++;
                            }
                        });
                        Notification::make()->title("{$count} marcadas verificadas remoto")->success()->send();
                    }),
            ])
            ->headerActions([
                Action::make('promoverAhora')
                    ->label('Promover ahora')
                    ->icon('heroicon-o-bolt')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Promueve a asignación legacy las filas con status=requiere_visita y fecha=hoy.')
                    ->action(function (): void {
                        $result = app(RevisionPendientePromotorService::class)->promoverDelDia();
                        Notification::make()
                            ->title("{$result['promoted']} promocionadas a asignación")
                            ->success()
                            ->send();
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLvRevisionPendientes::route('/'),
        ];
    }

    /** @return array<int, string> */
    private static function yearOptions(): array
    {
        $current = (int) now('Europe/Madrid')->year;
        return array_combine(range($current - 1, $current + 1), array_map('strval', range($current - 1, $current + 1)));
    }

    /** @return array<int, string> */
    private static function monthOptions(): array
    {
        return [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];
    }
}
```

### Step 6 — Page `ListLvRevisionPendientes`

`app/Filament/Resources/LvRevisionPendienteResource/Pages/ListLvRevisionPendientes.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\LvRevisionPendienteResource\Pages;

use App\Filament\Resources\LvRevisionPendienteResource;
use Filament\Resources\Pages\ListRecords;

final class ListLvRevisionPendientes extends ListRecords
{
    protected static string $resource = LvRevisionPendienteResource::class;
}
```

**No Create page**: el usuario nunca crea filas manualmente — solo el cron mensual las
genera. La omisión del slot `'create'` en `getPages()` ya hace que `CreateAction` y la
ruta `/create` no existan.

**No Edit page**: las actions inline cubren toda la edición. Coherente con el resto del
proyecto (PivResource, AsignacionResource).

**No View page**: las columnas + tooltip + slideOver del Resource cubren la lectura. Si
en el futuro hace falta vista detallada (con histórico de carry overs anteriores, etc.),
se añade en bloque dedicado.

### Step 7 — Tests Pest

**Distribuir entre 4 archivos** para mantener cohesión:

#### Test 7.1 — Helpers añadidos al modelo

Extender `tests/Feature/Models/LvRevisionPendienteSchemaTest.php` con:

```php
it('scope noPromocionadas filtra solo asignacion_id null', function () {
    $piv = Piv::factory()->create();
    LvRevisionPendiente::factory()->for($piv, 'piv')->create([
        'periodo_month' => 5, 'asignacion_id' => null,
    ]);
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->create([
        'periodo_month' => 5, 'asignacion_id' => 12345,
    ]);

    expect(LvRevisionPendiente::query()->noPromocionadas()->count())->toBe(1);
});

it('scope requiereVisitaParaFecha filtra status y fecha', function () {
    $piv = Piv::factory()->create();
    $today = CarbonImmutable::parse('2026-05-04');

    LvRevisionPendiente::factory()->for($piv, 'piv')->requiereVisita()->create([
        'fecha_planificada' => $today,
        'periodo_month' => 5,
    ]);
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create([
        'fecha_planificada' => $today,
        'periodo_month' => 5,
    ]);
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->requiereVisita()->create([
        'fecha_planificada' => $today->addDay(),
        'periodo_month' => 5,
    ]);

    expect(LvRevisionPendiente::query()->requiereVisitaParaFecha($today)->count())->toBe(1);
});
```

#### Test 7.2 — Service `RevisionPendientePromotorService`

`tests/Unit/Services/RevisionPendientePromotorServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Asignacion;
use App\Models\Averia;
use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use App\Services\RevisionPendientePromotorService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->svc = new RevisionPendientePromotorService();
});

it('promueve fila requiere_visita con fecha today crea averia + asignacion', function () {
    $piv = Piv::factory()->create();
    $today = CarbonImmutable::now('Europe/Madrid')->startOfDay();
    $row = LvRevisionPendiente::factory()->for($piv, 'piv')->requiereVisita()->create([
        'fecha_planificada' => $today,
    ]);

    $result = $this->svc->promoverDelDia($today);

    expect($result['promoted'])->toBe(1);
    expect(Averia::count())->toBe(1);
    expect(Asignacion::count())->toBe(1);

    $averia = Averia::first();
    expect($averia->piv_id)->toBe($piv->piv_id);
    expect($averia->notas)->toBe(RevisionPendientePromotorService::NOTAS_AVERIA_STUB);
    expect((int) $averia->status)->toBe(1);

    $asig = Asignacion::first();
    expect((int) $asig->tipo)->toBe(Asignacion::TIPO_REVISION);
    expect((int) $asig->status)->toBe(1);
    expect($asig->tecnico_id)->toBeNull();
    expect($asig->averia_id)->toBe($averia->averia_id);

    $row->refresh();
    expect($row->asignacion_id)->toBe($asig->asignacion_id);
});

it('idempotente: re-run no crea segunda asignacion', function () {
    $piv = Piv::factory()->create();
    $today = CarbonImmutable::now('Europe/Madrid')->startOfDay();
    LvRevisionPendiente::factory()->for($piv, 'piv')->requiereVisita()->create([
        'fecha_planificada' => $today,
    ]);

    $this->svc->promoverDelDia($today);
    $first = ['averia' => Averia::count(), 'asig' => Asignacion::count()];

    $this->svc->promoverDelDia($today);
    $second = ['averia' => Averia::count(), 'asig' => Asignacion::count()];

    expect($second['averia'])->toBe($first['averia']);
    expect($second['asig'])->toBe($first['asig']);
});

it('ignora filas con status pendiente', function () {
    $piv = Piv::factory()->create();
    $today = CarbonImmutable::now('Europe/Madrid')->startOfDay();
    LvRevisionPendiente::factory()->for($piv, 'piv')->pendiente()->create([
        'fecha_planificada' => $today,
    ]);

    $result = $this->svc->promoverDelDia($today);
    expect($result['promoted'])->toBe(0);
    expect(Averia::count())->toBe(0);
});

it('ignora fechas no coincidentes', function () {
    $piv = Piv::factory()->create();
    $today = CarbonImmutable::now('Europe/Madrid')->startOfDay();
    LvRevisionPendiente::factory()->for($piv, 'piv')->requiereVisita()->create([
        'fecha_planificada' => $today->addDays(3),
    ]);

    $result = $this->svc->promoverDelDia($today);
    expect($result['promoted'])->toBe(0);
});

it('default sin date usa now Europe/Madrid', function () {
    $piv = Piv::factory()->create();
    LvRevisionPendiente::factory()->for($piv, 'piv')->requiereVisita()->create([
        'fecha_planificada' => CarbonImmutable::now('Europe/Madrid')->startOfDay(),
    ]);

    $result = $this->svc->promoverDelDia();
    expect($result['promoted'])->toBe(1);
});

it('ignora filas requiere_visita ya promocionadas', function () {
    $piv = Piv::factory()->create();
    $today = CarbonImmutable::now('Europe/Madrid')->startOfDay();
    LvRevisionPendiente::factory()->for($piv, 'piv')->requiereVisita()->create([
        'fecha_planificada' => $today,
        'asignacion_id' => 99999, // ya promocionada
    ]);

    $result = $this->svc->promoverDelDia($today);
    expect($result['promoted'])->toBe(0);
});
```

#### Test 7.3 — Console command

`tests/Feature/Console/PromoteRevisionesToAsignacionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use Carbon\CarbonImmutable;

it('comando con --date promueve para esa fecha', function () {
    $piv = Piv::factory()->create();
    LvRevisionPendiente::factory()->for($piv, 'piv')->requiereVisita()->create([
        'fecha_planificada' => '2026-05-15',
    ]);

    $this->artisan('lv:promote-revisiones-to-asignacion', ['--date' => '2026-05-15'])
        ->assertSuccessful();

    expect(LvRevisionPendiente::first()->fresh()->asignacion_id)->not->toBeNull();
});

it('comando con --date inválida devuelve INVALID', function () {
    $this->artisan('lv:promote-revisiones-to-asignacion', ['--date' => 'no-es-fecha'])
        ->assertFailed();
});

it('comando sin --date usa today Europe/Madrid', function () {
    $piv = Piv::factory()->create();
    LvRevisionPendiente::factory()->for($piv, 'piv')->requiereVisita()->create([
        'fecha_planificada' => CarbonImmutable::now('Europe/Madrid')->startOfDay(),
    ]);

    $this->artisan('lv:promote-revisiones-to-asignacion')->assertSuccessful();
    expect(LvRevisionPendiente::first()->fresh()->asignacion_id)->not->toBeNull();
});

it('cron daily registrado en schedule', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($e) => $e->command);

    expect($events->contains(fn ($c) =>
        is_string($c) && str_contains($c, 'lv:promote-revisiones-to-asignacion')
    ))->toBeTrue();
});
```

#### Test 7.4 — Filament Resource

`tests/Feature/Filament/LvRevisionPendienteResourceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\LvRevisionPendienteResource;
use App\Filament\Resources\LvRevisionPendienteResource\Pages\ListLvRevisionPendientes;
use App\Models\LvRevisionPendiente;
use App\Models\Piv;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('admin puede acceder al listado de revisiones pendientes', function () {
    $this->get('/admin/revisiones-pendientes')->assertOk();
});

it('non-admin no puede acceder al listado', function () {
    $this->actingAs(User::factory()->tecnico()->create());
    $this->get('/admin/revisiones-pendientes')->assertForbidden();
});

it('lista muestra filas pendientes del mes actual', function () {
    $now = CarbonImmutable::now('Europe/Madrid');
    $piv = Piv::factory()->create();
    $row = LvRevisionPendiente::factory()->for($piv, 'piv')->pendiente()->create([
        'periodo_year' => $now->year,
        'periodo_month' => $now->month,
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->assertCanSeeTableRecords([$row]);
});

it('action verificarRemoto cambia status y registra decision_user', function () {
    $piv = Piv::factory()->create();
    $row = LvRevisionPendiente::factory()->for($piv, 'piv')->pendiente()->create([
        'periodo_year' => CarbonImmutable::now('Europe/Madrid')->year,
        'periodo_month' => CarbonImmutable::now('Europe/Madrid')->month,
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->callTableAction('verificarRemoto', $row, ['decision_notas' => 'Visto OK desde panel externo'])
        ->assertHasNoTableActionErrors();

    $row->refresh();
    expect($row->status)->toBe(LvRevisionPendiente::STATUS_VERIFICADA_REMOTO);
    expect($row->decision_user_id)->toBe($this->admin->id);
    expect($row->decision_notas)->toBe('Visto OK desde panel externo');
    expect($row->decision_at)->not->toBeNull();
});

it('action requiereVisita exige fecha y la guarda', function () {
    $piv = Piv::factory()->create();
    $row = LvRevisionPendiente::factory()->for($piv, 'piv')->pendiente()->create([
        'periodo_year' => CarbonImmutable::now('Europe/Madrid')->year,
        'periodo_month' => CarbonImmutable::now('Europe/Madrid')->month,
    ]);

    $fecha = CarbonImmutable::now('Europe/Madrid')->addDay()->format('Y-m-d');

    Livewire::test(ListLvRevisionPendientes::class)
        ->callTableAction('requiereVisita', $row, ['fecha_planificada' => $fecha])
        ->assertHasNoTableActionErrors();

    $row->refresh();
    expect($row->status)->toBe(LvRevisionPendiente::STATUS_REQUIERE_VISITA);
    expect($row->fecha_planificada->format('Y-m-d'))->toBe($fecha);
});

it('action requiereVisita rechaza fecha pasada', function () {
    $piv = Piv::factory()->create();
    $row = LvRevisionPendiente::factory()->for($piv, 'piv')->pendiente()->create([
        'periodo_year' => CarbonImmutable::now('Europe/Madrid')->year,
        'periodo_month' => CarbonImmutable::now('Europe/Madrid')->month,
    ]);

    $ayer = CarbonImmutable::now('Europe/Madrid')->subDay()->format('Y-m-d');

    Livewire::test(ListLvRevisionPendientes::class)
        ->callTableAction('requiereVisita', $row, ['fecha_planificada' => $ayer])
        ->assertHasTableActionErrors(['fecha_planificada']);
});

it('action marcarExcepcion exige notas', function () {
    $piv = Piv::factory()->create();
    $row = LvRevisionPendiente::factory()->for($piv, 'piv')->pendiente()->create([
        'periodo_year' => CarbonImmutable::now('Europe/Madrid')->year,
        'periodo_month' => CarbonImmutable::now('Europe/Madrid')->month,
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->callTableAction('marcarExcepcion', $row, ['decision_notas' => ''])
        ->assertHasTableActionErrors(['decision_notas']);

    Livewire::test(ListLvRevisionPendientes::class)
        ->callTableAction('marcarExcepcion', $row, ['decision_notas' => 'Panel retirado del servicio.'])
        ->assertHasNoTableActionErrors();
    $row->refresh();
    expect($row->status)->toBe(LvRevisionPendiente::STATUS_EXCEPCION);
});

it('action revertir restaura a pendiente y limpia decision_*', function () {
    $piv = Piv::factory()->create();
    $row = LvRevisionPendiente::factory()->for($piv, 'piv')->verificadaRemoto()->create([
        'periodo_year' => CarbonImmutable::now('Europe/Madrid')->year,
        'periodo_month' => CarbonImmutable::now('Europe/Madrid')->month,
        'decision_user_id' => $this->admin->id,
        'decision_notas' => 'algo',
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->callTableAction('revertir', $row)
        ->assertHasNoTableActionErrors();

    $row->refresh();
    expect($row->status)->toBe(LvRevisionPendiente::STATUS_PENDIENTE);
    expect($row->decision_user_id)->toBeNull();
    expect($row->decision_notas)->toBeNull();
    expect($row->decision_at)->toBeNull();
});

it('action revertir NO visible si fila ya promocionada (asignacion_id != null)', function () {
    $piv = Piv::factory()->create();
    $row = LvRevisionPendiente::factory()->for($piv, 'piv')->requiereVisita()->create([
        'asignacion_id' => 99999,
        'periodo_year' => CarbonImmutable::now('Europe/Madrid')->year,
        'periodo_month' => CarbonImmutable::now('Europe/Madrid')->month,
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->assertTableActionHidden('revertir', $row);
});

it('bulk verificarRemoto marca solo filas pendientes', function () {
    $month = CarbonImmutable::now('Europe/Madrid');
    $rowsPendientes = collect();
    for ($i = 0; $i < 3; $i++) {
        $rowsPendientes->push(LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create([
            'periodo_year' => $month->year, 'periodo_month' => $month->month,
        ]));
    }
    $rowExcepcion = LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->excepcion()->create([
        'periodo_year' => $month->year, 'periodo_month' => $month->month,
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->callTableBulkAction('verificarRemotoBulk', $rowsPendientes->push($rowExcepcion), ['decision_notas' => null])
        ->assertHasNoTableBulkActionErrors();

    foreach ($rowsPendientes as $r) {
        expect($r->fresh()->status)->toBe(LvRevisionPendiente::STATUS_VERIFICADA_REMOTO);
    }
    expect($rowExcepcion->fresh()->status)->toBe(LvRevisionPendiente::STATUS_EXCEPCION);
});

it('header action promoverAhora ejecuta el cron del día', function () {
    $piv = Piv::factory()->create();
    LvRevisionPendiente::factory()->for($piv, 'piv')->requiereVisita()->create([
        'fecha_planificada' => CarbonImmutable::now('Europe/Madrid')->startOfDay(),
        'periodo_year' => CarbonImmutable::now('Europe/Madrid')->year,
        'periodo_month' => CarbonImmutable::now('Europe/Madrid')->month,
    ]);

    Livewire::test(ListLvRevisionPendientes::class)
        ->callAction('promoverAhora')
        ->assertHasNoActionErrors();

    expect(LvRevisionPendiente::first()->fresh()->asignacion_id)->not->toBeNull();
});

it('navigation badge cuenta solo pendientes del mes actual', function () {
    $now = CarbonImmutable::now('Europe/Madrid');
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create([
        'periodo_year' => $now->year, 'periodo_month' => $now->month,
    ]);
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->verificadaRemoto()->create([
        'periodo_year' => $now->year, 'periodo_month' => $now->month,
    ]);
    LvRevisionPendiente::factory()->for(Piv::factory(), 'piv')->pendiente()->create([
        'periodo_year' => 2024, 'periodo_month' => 1, // mes anterior
    ]);

    expect(LvRevisionPendienteResource::getNavigationBadge())->toBe('1');
});

it('resource tiene slug explicito revisiones-pendientes evita pluralizer bug', function () {
    expect(LvRevisionPendienteResource::getSlug())->toBe('revisiones-pendientes');
    expect(LvRevisionPendienteResource::getNavigationLabel())->toBe('Decisiones del día');
    expect(LvRevisionPendienteResource::getNavigationGroup())->toBe('Planificación');
});
```

### Step 8 — Smoke local (text-only)

```bash
# 1. Tests verde local
php artisan test
./vendor/bin/pint --test

# 2. Verificar Resource registrado
php artisan filament:list-resources | grep -i revisiones

# 3. Tinker test del cron service contra DB local (con factories)
php artisan tinker --execute='
use App\Models\Piv; use App\Models\LvRevisionPendiente;
$piv = Piv::factory()->create();
LvRevisionPendiente::factory()->for($piv, "piv")->requiereVisita()->create([
    "fecha_planificada" => now("Europe/Madrid")->startOfDay(),
]);
$result = app(\App\Services\RevisionPendientePromotorService::class)->promoverDelDia();
echo "promoted=" . $result["promoted"] . PHP_EOL;
$row = LvRevisionPendiente::first()->fresh();
echo "asignacion_id ahora: " . $row->asignacion_id . PHP_EOL;
'

# 4. Smoke browser (manual): arrancar `php artisan serve`, login admin, /admin/revisiones-pendientes,
#    verificar 4 actions visibles solo en pendientes, ColorColumn status badges, filtros funcionando.
```

## DoD

- [ ] Modelo `LvRevisionPendiente` con 2 scopes nuevos (`noPromocionadas`, `requiereVisitaParaFecha`).
- [ ] Service `RevisionPendientePromotorService::promoverDelDia()` con transaction, idempotente, tecnico_id NULL.
- [ ] Console command `lv:promote-revisiones-to-asignacion --date=` con default today Europe/Madrid + INVALID si fecha mal formada.
- [ ] Schedule daily 06:00 Europe/Madrid registrado (`->onOneServer()->name(...)`).
- [ ] `LvRevisionPendienteResource` con slug explícito `revisiones-pendientes`, nav group `Planificación`, nav label `Decisiones del día`, badge contador filas pendientes mes actual.
- [ ] Tabla con 7 columnas (carry badge tooltip, panel piv_id+parada, zona join, status badge, fecha, decidido por, notas truncadas).
- [ ] 5 filtros (zona, status, carry ternary, fecha picker, mes/año default current).
- [ ] Default sort: carry NULLS LAST → status custom order → piv_id.
- [ ] 4 actions inline (verificarRemoto, requiereVisita con minDate=today, marcarExcepcion notas required, revertir si no completada y no promocionada).
- [ ] 1 bulk action verificarRemoto (ignora silenciosamente las que no son pendientes).
- [ ] 1 header action promoverAhora.
- [ ] `User::isAdmin()` gating: non-admin → 403 en /admin/revisiones-pendientes.
- [ ] Tests Pest verde: ~24 nuevos. Suite total 263 → ≥287 verde.
- [ ] CI 3/3 verde.
- [ ] Pint clean.
- [ ] Smoke local tinker + browser (al menos navegar /admin/revisiones-pendientes y ver tabla render sin crash).

## Smoke real obligatorio post-merge

**Antes**: backup fresh cifrado prod (runbook nuevo).

**Smoke prod**:

1. `php artisan migrate --pretend` → debe ser CERO migrations nuevas (este bloque no añade schema).
2. Recargar Filament admin (clear cache si necesario): `php artisan optimize:clear`.
3. Login admin `/admin/login` → verificar sidebar nuevo grupo "Planificación" → "Decisiones del día" con badge mostrando 484 (las pendientes mayo).
4. Click → tabla renderiza con 484 filas paginadas. Filtro "Mes" default mayo 2026.
5. Filtro Zona = "Madrid Sur" → 16-17 filas (paneles asignados a Madrid Sur).
6. Pick 1 fila pendiente real → action "Verificar remoto" → modal abre con textarea. Guardar con notas "Smoke 12b.4 — verificada via panel externo". Verificar status = verificada_remoto + decision_user = admin.
7. Pick otra fila pendiente real → action "Requiere visita" → date picker default today, ajustar a today. Verificar status = requiere_visita + fecha_planificada = today.
8. Header action "Promover ahora" → confirmación → ejecuta. Notification "1 promocionadas". Verificar:
   - `lv_revision_pendiente.asignacion_id` not null.
   - `averia` legacy +1 fila con `notas='Revisión preventiva mensual'`, `piv_id` correcto.
   - `asignacion` legacy +1 fila con `tipo=2 status=1 tecnico_id=NULL averia_id` correcto.
9. Re-click "Promover ahora" → 0 promocionadas (idempotente).
10. Action "Revertir" sobre la fila verificada_remoto → vuelve a pendiente. Action "Revertir" NO visible sobre la fila requiere_visita (ya promocionada, asignacion_id != null).
11. Pick 1 fila pendiente → action "Excepción" → modal exige notas → guardar con notas "Panel en obras municipales".
12. Cleanup: revertir las decisiones del smoke (verificada_remoto y excepcion) a pendiente vía action "Revertir". La fila requiere_visita + asignacion creada NO se revierte aquí (queda como "smoke trail"). Borrar manualmente:
    - `DELETE FROM asignacion WHERE asignacion_id = $id` (registrado en cleanup).
    - `DELETE FROM averia WHERE averia_id = $id`.
    - `UPDATE lv_revision_pendiente SET status='pendiente', fecha_planificada=NULL, decision_*=NULL, asignacion_id=NULL WHERE id = $id`.
13. Estado final prod: 484 filas pendientes mayo intactas. Cero contaminación legacy.

## Riesgos y decisiones diferidas (cubrir en REPORTE FINAL)

1. **Filament closure name resolution**: usar `$infolist` en cualquier closure que reciba Infolist (no `$i`). Bloque 08c. Tests no lo cazan; smoke real obligatorio.
2. **Filament pluralizer**: `$slug` y `$pluralModelLabel` explícitos para evitar `revisionspendientes` u otros slugs raros (Bloque 08b).
3. **Cron daily 06:00 NO activo en prod hasta Bloque 14** — SiteGround GoGeek requiere configuración Site Tools UI. Mientras tanto, header action "Promover ahora" cubre el caso de uso manual.
4. **`tecnico_id` NULL en `asignacion`**: admin asigna posteriormente desde `AsignacionResource`. Si en el futuro el flujo necesita auto-asignación (round-robin por zona), se añade en bloque 12b.5 o módulo dedicado.
5. **Filtro Zona usa subquery con join** — verificar que con 484 filas + 102 municipios el plan de query es razonable. Tests cubren resultados; performance no se mide en tests Pest.
6. **Bulk verificarRemoto sin límite de selección**: Filament permite seleccionar TODA la página o TODA la query. Si admin selecciona 484 filas y bulk verifica, es 1 transacción. Aceptable.

## REPORTE FINAL (formato esperado)

```
## Bloque 12b.4 — REPORTE FINAL

### Estado
- Branch: bloque-12b4-decisiones-del-dia
- Commits: N
- Tests: 263 → 287 verde (~24 nuevos: Resource + Service + Console + helpers).
- CI: 3/3 verde sobre HEAD <hash>
- Pint: clean
- Smoke tinker + browser (Filament resource render OK).

### Decisiones aplicadas
- 8 puntos cerrados con usuario antes del prompt.
- Cron daily registrado pero NO activo en prod hasta Bloque 14.

### Riesgos/pendientes para review
- Smoke real prod obligatorio antes de cerrar el bloque.
- Verificar que el filtro Zona JOIN no degrada con 484 filas reales.

### Pivots respecto al prompt
- (si los hubo, listar y justificar)
```

---

## Aplicación de la checklist obligatoria (memoria proyecto)

| Sección | Aplicado | Cómo |
|---|---|---|
| 1. Compatibilidad framework | ✓ | Filament 3 con slug explícito (Bloque 08b), `$infolist` no `$i` mencionado en restricciones (Bloque 08c), no RelationManager en ViewRecord (Bloque 08g/h). Cron Laravel Schedule con timezone explícito. |
| 2. Inferir de app vieja | N/A | App vieja PHP 2014 NO tiene UI de "decisiones del día" — feature nueva. La avería-stub legacy sí está documentada (ADR-0001 + ADR-0004) y el cron la replica fielmente. |
| 3. Smoke real obligatorio | ✓ | Bloque toca BD prod (INSERT averia + asignacion en legacy + UPDATE lv_revision_pendiente). Smoke real obligatorio post-merge con backup fresh cifrado + cleanup explícito de las 1-3 filas creadas durante el smoke. |
| 4. Test pivots = banderazo rojo | ✓ | Tests Filament usan Livewire helpers reales (`callTableAction`, `callTableBulkAction`, `callAction`), no mocks. Si Copilot pivota a tests unit-only del modelo, banderazo. |
| 5. Datos prod-shaped | ✓ | Tests usan factories pero el smoke real prod ejecuta sobre 484 filas reales con 102 municipios (filtro zona) + diferentes statuses simulados. Verificación legacy intacta post-smoke. |
