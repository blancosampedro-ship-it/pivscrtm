# Bloque 11d — UX redesign PWA técnico field-grade (Glovo-pattern)

## Contexto

Bloques 11a (PR #25), 11ab (PR #26) y 11b (PR #27) están mergeados en `main`. La PWA técnica funciona end-to-end: login, dashboard "Mis asignaciones abiertas", cierre flow correctivo + revisión, foto upload. Suite **181 verde** + smoke real 11b ejecutado el 3 may 2026 con cleanup limpio.

**Lo que NO funciona**: el flujo actual está pensado "para informáticos en oficina", no para el usuario real:

> Técnico con guantes, en frío, con lluvia, en marquesina, con dedos torpes y prisa, no informático, sin paciencia para typing.

Este bloque rediseña la PWA técnico desde la perspectiva del campo. **No añade funcionalidad nueva** — la lógica del cierre (`AsignacionCierreService`), las relaciones, los modelos quedan exactamente igual. Solo cambia el **markup, los inputs, el flujo de pantallas, y la duración de sesión**.

## Audiencia y constraints decididos

Confirmados con el usuario antes de este bloque:

- **≤10 técnicos**, cada uno con su propio móvil/tablet personal estable.
- **≤20 cierres/día** por técnico. Cada segundo cuenta.
- **Patrones Glovo aceptables**: foto-first, multi-step con 1 decisión por pantalla, botones gigantes color-coded, swipe/tap forward.
- **Smartphones modernos**: Web Speech API, cámara HD, vibration API, viewport responsive.
- **La asignación llega por la app** (push notifications real → Bloque 13). Mientras tanto: polling Livewire 30s + animación pulsing en cards nuevas.
- **Sesión 90 días**: técnico no re-loguea entre sesiones. Si admin desactiva técnico, middleware `EnsureTecnico` ya expulsa (validado en Parte C 11ab).
- **Recambios precargados**: 8 items hardcoded para empezar (`Cable`, `Conector`, `Fuente alimentación`, `Batería`, `Pantalla`, `Tarjeta SIM`, `GPS`, `Marquesina`) + opción "Otro" con input. **Nota futura**: cuando crezca la lista, migrar a tabla `lv_recambio_catalog` editable desde admin.
- **Tiempo presets**: 7 botones — `5min / 15min / 30min / 1h / 1h30 / 2h / +más`.
- **Foto del panel en card del dashboard**: prioridad (1) última `lv_correctivo_imagen` del panel, (2) primera `piv_imagen` legacy, (3) placeholder si no hay nada. La foto que el técnico sube en cada cierre se convierte automáticamente en la "actual" del panel para futuros visitantes.

## Nota sobre legacy

**No replicar legacy para técnico.** La app vieja `winfin.es/*.php` no tiene flujo de técnico. El diseño es nuevo, basado en patrones Glovo/Wolt/Stuart, con la lógica del cierre del Bloque 09/11b ya validada en BD.

## Restricciones inviolables

- **NO modificar `app/Services/AsignacionCierreService.php`**. La lógica del cierre se mantiene idéntica. Este bloque solo cambia cómo el técnico llega al `cerrar(...)`.
- **NO modificar el admin Filament** (`AsignacionResource`, `TecnicoResource`, etc.). Solo PWA técnico.
- **NO modificar `app/Auth/LegacyHashGuard.php`** ni `lv_users` schema.
- **NO escribir en `piv_imagen` legacy** desde el cierre. Las fotos del cierre siguen yendo a `lv_correctivo_imagen` (ADR-0006). El "set as current" del panel es solo display logic, no escritura legacy.
- **ADR-0004 / Regla #3 RGPD**: NUNCA escribir `averia.notas` desde el cierre técnico.
- **Tests Pest verde obligatorio**: 181 actuales no se rompen, sumar tests del nuevo flujo, terminar ≥190 verde.
- **CI verde** (3 jobs) antes de PR ready.
- **Carbon visual** se conserva como base estética (DESIGN.md), pero **se relaja para el técnico**: tap targets ≥64px (no 44 estándar), tipografía 18-20px, botones color-coded semánticos.

## Plan de cambios

### Step 1 — Sesión persistente 90 días

`config/session.php` o `.env`:

```env
SESSION_LIFETIME=129600
SESSION_EXPIRE_ON_CLOSE=false
```

129600 minutos = 90 días. `expire_on_close=false` para que cerrar el navegador no invalide la sesión.

**No cambia el flujo de login**. Solo el lifetime de la cookie + de la fila en `lv_sessions`.

Test: `tecnico_session_lifetime_is_90_days` que verifica que `config('session.lifetime') === 129600`.

### Step 2 — Piv accessor `current_photo_url`

En `app/Models/Piv.php`, añadir:

```php
/**
 * URL de la foto "actual" del panel para mostrar en cards del dashboard.
 * Prioriza:
 *   1. La última lv_correctivo_imagen de cualquier correctivo del panel
 *      (la última cosa que un técnico vio + fotografió, fuente más fresca).
 *   2. Si no hay correctivos con foto, la primera piv_imagen legacy
 *      (mantiene compatibilidad con el dataset legacy de 65k fotos).
 *   3. null si el panel no tiene nada — el frontend muestra un placeholder.
 *
 * NO escribe en piv_imagen legacy. Solo display logic.
 */
public function getCurrentPhotoUrlAttribute(): ?string
{
    // Última lv_correctivo_imagen via correctivo → asignacion → averia → piv.
    $latestCierre = LvCorrectivoImagen::query()
        ->whereIn('correctivo_id', function ($query) {
            $query->select('correctivo.correctivo_id')
                ->from('correctivo')
                ->join('asignacion', 'correctivo.asignacion_id', '=', 'asignacion.asignacion_id')
                ->join('averia', 'asignacion.averia_id', '=', 'averia.averia_id')
                ->where('averia.piv_id', $this->piv_id);
        })
        ->orderByDesc('lv_correctivo_imagen_id')
        ->first();

    if ($latestCierre !== null) {
        // Foto subida vía PWA → vive en storage local public/piv-images/correctivo/
        return Storage::disk('public')->url($latestCierre->url);
    }

    // Fallback al accessor legacy ya existente (winfin.es/images/piv/<url>)
    return $this->thumbnail_url;
}
```

Tests:
- `piv_current_photo_url_uses_latest_correctivo_image_when_available` (creando correctivo + lv_correctivo_imagen, assert URL incluye `/storage/piv-images/correctivo/`).
- `piv_current_photo_url_falls_back_to_legacy_thumbnail_when_no_correctivo` (con piv_imagen legacy, assert URL incluye `winfin.es/images/piv/`).
- `piv_current_photo_url_returns_null_when_no_image_anywhere` (panel limpio).

### Step 3 — Dashboard rediseñado

Reemplazar `resources/views/livewire/tecnico/dashboard.blade.php`. La estructura nueva:

```php
<?php
use App\Models\Asignacion;
use App\Models\Tecnico;
use Livewire\Volt\Component;
use function Livewire\Volt\layout;
use function Livewire\Volt\title;

layout('components.tecnico.shell');
title('Winfin PIV — Mis cierres');

new class extends Component {
    public int $previousCount = 0;
    public bool $hasNewSinceLastPoll = false;

    public function with(): array
    {
        $tecnicoId = (int) auth()->user()->legacy_id;

        $asignacionesAbiertas = Asignacion::query()
            ->where('tecnico_id', $tecnicoId)
            ->where('status', 1)
            ->with(['averia.piv'])
            ->orderByDesc('fecha')
            ->get();

        // Detección de nueva asignación entre poll y poll
        $count = $asignacionesAbiertas->count();
        $this->hasNewSinceLastPoll = $count > $this->previousCount;
        $this->previousCount = $count;

        $tecnico = Tecnico::find($tecnicoId);

        return [
            'asignacionesAbiertas' => $asignacionesAbiertas,
            'tecnicoNombre' => $tecnico?->nombre_completo ?? '—',
            'hasNew' => $this->hasNewSinceLastPoll,
        ];
    }
}; ?>

<div class="min-h-screen bg-bg-primary" wire:poll.30s>
    {{-- Header con saludo + logout --}}
    <header class="bg-layer-0 border-b border-default px-4 py-3 flex items-center justify-between">
        <div>
            <div class="text-xs uppercase tracking-wider text-ink-secondary">Hola</div>
            <div class="text-lg font-medium leading-tight">{{ $tecnicoNombre }}</div>
        </div>
        <form action="{{ route('tecnico.logout') }}" method="POST">
            @csrf
            <button type="submit"
                    class="px-4 py-3 text-sm font-medium border border-default hover:bg-layer-1"
                    aria-label="Salir">
                ⏏ Salir
            </button>
        </form>
    </header>

    {{-- Flash de cierre --}}
    @if (session('cierre_ok'))
        <div class="bg-success-subtle text-success-text border-l-4 border-success p-4 mx-4 mt-4 text-md font-medium" role="status">
            ✅ {{ session('cierre_ok') }}
        </div>
    @endif

    {{-- Cuerpo --}}
    <main class="p-4">
        @if ($asignacionesAbiertas->isEmpty())
            <div class="bg-layer-1 p-12 text-center">
                <div class="text-4xl mb-3">☕</div>
                <div class="text-md text-ink-secondary">No hay nada pendiente.</div>
                <div class="text-sm text-ink-secondary mt-1">Cuando te asignen una avería aparecerá aquí.</div>
            </div>
        @else
            <h2 class="text-xs uppercase tracking-wider text-ink-secondary font-medium mb-3">
                {{ $asignacionesAbiertas->count() }} {{ $asignacionesAbiertas->count() === 1 ? 'asignación abierta' : 'asignaciones abiertas' }}
            </h2>

            <ul class="space-y-3" role="list">
                @foreach ($asignacionesAbiertas as $asignacion)
                    @php
                        $isCorrectivo = (int) $asignacion->tipo === \App\Models\Asignacion::TIPO_CORRECTIVO;
                        $piv = $asignacion->averia?->piv;
                        $photoUrl = $piv?->current_photo_url;
                        $kicker = $isCorrectivo ? '⚠ AVERÍA REAL' : '✓ REVISIÓN MENSUAL';
                        $kickerColor = $isCorrectivo ? 'text-error' : 'text-success';
                        $isNew = $hasNew && $loop->first; // crude pero suficiente: solo la primera card "nueva" pulsa
                    @endphp
                    <li>
                        <a href="{{ route('tecnico.asignacion.cierre', $asignacion) }}"
                           class="block bg-layer-0 border border-default hover:border-border-strong active:bg-layer-1 transition-colors {{ $isNew ? 'animate-pulse-slow ring-2 ring-primary-60' : '' }}">
                            <div class="flex">
                                {{-- Foto miniatura 96x96 --}}
                                <div class="w-24 h-24 flex-shrink-0 bg-layer-1 overflow-hidden">
                                    @if ($photoUrl)
                                        <img src="{{ $photoUrl }}"
                                             alt="Panel {{ $piv?->piv_id }}"
                                             class="w-full h-full object-cover"
                                             loading="lazy"
                                             onerror="this.src='/img/panel-placeholder.svg'; this.classList.add('opacity-40');">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center text-3xl text-ink-secondary opacity-40">📷</div>
                                    @endif
                                </div>

                                {{-- Texto --}}
                                <div class="flex-1 p-3 flex flex-col justify-center min-w-0">
                                    <div class="text-xs uppercase tracking-wider {{ $kickerColor }} font-medium">
                                        {{ $kicker }}
                                    </div>
                                    @if ($piv)
                                        <div class="text-md font-medium leading-tight truncate">
                                            Panel #{{ str_pad((string) $piv->piv_id, 3, '0', STR_PAD_LEFT) }}
                                        </div>
                                        <div class="text-sm text-ink-secondary leading-snug truncate">
                                            {{ $piv->direccion }}
                                        </div>
                                    @else
                                        <div class="text-md font-medium text-ink-secondary">Sin panel asignado</div>
                                    @endif
                                </div>

                                {{-- Chevron --}}
                                <div class="flex items-center justify-center px-3 text-ink-secondary text-2xl">→</div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </main>
</div>
```

Notas:
- `wire:poll.30s` polling cada 30s sin tocar nada del backend. Auto-refresh.
- Cards con foto miniatura 96×96 (cuadrado, fácil de visualizar a cualquier tamaño).
- Tap target full-card-width. Border-only hover/active states.
- `animate-pulse-slow` (definir en `tailwind.config.js` como animation custom): 2s pulsing para destacar nueva asignación.
- Empty state amigable con emoji ☕ ("no hay nada pendiente, vete a por un café").
- Logout es un botón explícito, no un icono pequeño.

Añadir en `tailwind.config.js`:

```js
animation: {
    'pulse-slow': 'pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
},
```

Y crear `public/img/panel-placeholder.svg` (placeholder neutro, gris, icono de panel).

### Step 4 — Cierre flow multi-step (Volt component nuevo)

Reemplazar `resources/views/livewire/tecnico/cierre.blade.php` con un component multi-step. El estado (`$step`, los datos) se mantiene en el component, y `wire:click="next"` / `wire:click="prev"` cambia de pantalla.

Skeleton:

```php
<?php
use App\Models\Asignacion;
use App\Services\AsignacionCierreService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\layout;
use function Livewire\Volt\title;

layout('components.tecnico.shell');
title('Winfin PIV — Cierre');

new class extends Component {
    use WithFileUploads;

    public Asignacion $asignacion;
    public int $step = 1; // 1..4 correctivo, 1..2 revisión

    // Correctivo
    public string $estadoFinal = '';      // 'reparado' | 'pendiente' | 'no_reparable'
    /** @var array<int, string> */
    public array $recambios = [];          // ['Conector', 'Cable']
    public string $recambioOtro = '';      // texto si seleccionó "Otro"
    public string $tiempoMinutos = '';     // '15', '30', '60', '90', '120' o custom

    // Revisión
    public string $aspecto = 'OK';
    public string $funcionamiento = 'OK';
    public string $actuacion = 'OK';
    public string $audio = 'OK';
    public string $lineas = 'OK';
    public string $precisionPaso = 'OK';

    // Compartidos
    public string $notas = '';
    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $fotos = [];

    public const RECAMBIOS_DISPONIBLES = [
        'Cable',
        'Conector',
        'Fuente alimentación',
        'Batería',
        'Pantalla',
        'Tarjeta SIM',
        'GPS',
        'Marquesina',
    ];

    public const TIEMPOS_DISPONIBLES = [
        ['value' => '5', 'label' => '5 min'],
        ['value' => '15', 'label' => '15 min'],
        ['value' => '30', 'label' => '30 min'],
        ['value' => '60', 'label' => '1 h'],
        ['value' => '90', 'label' => '1h 30'],
        ['value' => '120', 'label' => '2 h'],
    ];

    public function mount(Asignacion $asignacion): void
    {
        $tecnicoId = (int) auth()->user()->legacy_id;
        abort_unless((int) $asignacion->tecnico_id === $tecnicoId, 403);
        abort_unless((int) $asignacion->status === 1, 410);

        $this->asignacion = $asignacion->load(['averia.piv']);
    }

    public function next(): void
    {
        $this->validateCurrentStep();
        $this->step++;
    }

    public function prev(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function setEstadoFinal(string $value): void
    {
        $this->estadoFinal = $value;
        $this->step++; // Auto-advance tras tap (Glovo pattern)
    }

    public function toggleRecambio(string $name): void
    {
        if (in_array($name, $this->recambios, true)) {
            $this->recambios = array_values(array_diff($this->recambios, [$name]));
        } else {
            $this->recambios[] = $name;
        }
    }

    public function setTiempo(string $value): void
    {
        $this->tiempoMinutos = $value;
        $this->step++;
    }

    public function setRevisionItem(string $field, string $value): void
    {
        $this->{$field} = $value;
    }

    private function validateCurrentStep(): void
    {
        $isCorrectivo = (int) $this->asignacion->tipo === Asignacion::TIPO_CORRECTIVO;

        if ($isCorrectivo) {
            match ($this->step) {
                1 => $this->validate(['estadoFinal' => 'required|in:reparado,pendiente,no_reparable']),
                2 => null, // recambios opcional, se permite seguir aunque sea []
                3 => $this->validate(['tiempoMinutos' => 'required|string']),
                default => null,
            };
        }
    }

    public function cerrar(AsignacionCierreService $service): void
    {
        $isCorrectivo = (int) $this->asignacion->tipo === Asignacion::TIPO_CORRECTIVO;

        $this->validate([
            'fotos' => ['nullable', 'array', 'max:10'],
            'fotos.*' => ['image', 'max:8192'],
            'notas' => ['nullable', 'string', 'max:255'],
        ]);

        // Persistir fotos
        $fotosPaths = [];
        foreach ($this->fotos as $f) {
            $fotosPaths[] = $f->store('piv-images/correctivo', 'public');
        }

        // Construir data para el service usando el shape que ya espera (Bloque 11b)
        $data = $isCorrectivo
            ? [
                'diagnostico' => $this->buildDiagnostico(),
                'recambios' => $this->buildRecambios(),
                'estado_final' => $this->mapEstadoFinal(),
                'tiempo' => $this->mapTiempoToHoras(),
                'fotos' => $fotosPaths,
                'contrato' => false,
                'facturar_horas' => false,
                'facturar_desplazamiento' => false,
                'facturar_recambios' => false,
            ]
            : [
                'aspecto' => $this->aspecto,
                'funcionamiento' => $this->funcionamiento,
                'actuacion' => $this->actuacion,
                'audio' => $this->audio,
                'lineas' => $this->lineas,
                'precision_paso' => $this->precisionPaso,
                'notas' => $this->notas ?: null,
                'fecha' => now()->format('Y-m-d'),
            ];

        try {
            $service->cerrar($this->asignacion, $data);
        } catch (ValidationException $e) {
            $this->addError('cerrar', collect($e->errors())->flatten()->first() ?? 'No se pudo cerrar.');
            return;
        }

        session()->flash('cierre_ok', 'Asignación #'.$this->asignacion->asignacion_id.' cerrada.');
        $this->redirect(route('tecnico.dashboard'), navigate: false);
    }

    private function buildDiagnostico(): string
    {
        // Diagnóstico = estado_final humano + notas técnico si las hay.
        $base = match ($this->estadoFinal) {
            'reparado' => 'Reparado',
            'pendiente' => 'Pendiente segunda visita',
            'no_reparable' => 'No reparable',
            default => '',
        };
        return $this->notas ? $base.'. '.$this->notas : $base;
    }

    private function buildRecambios(): string
    {
        $items = $this->recambios;
        if ($this->recambioOtro !== '') {
            $items[] = $this->recambioOtro;
        }
        return $items === [] ? '—' : implode(', ', $items);
    }

    private function mapEstadoFinal(): string
    {
        return match ($this->estadoFinal) {
            'reparado' => 'OK',
            'pendiente' => 'Pendiente segunda visita',
            'no_reparable' => 'No reparable',
            default => 'OK',
        };
    }

    private function mapTiempoToHoras(): string
    {
        // El service de Bloque 11b acepta string libre en `tiempo`.
        // Convertimos minutos → fracción horas string para mantener consistencia con admin.
        $mins = (int) $this->tiempoMinutos;
        if ($mins === 0) return '';
        $horas = $mins / 60;
        return rtrim(rtrim(number_format($horas, 2, '.', ''), '0'), '.');
    }
}; ?>

<div class="min-h-screen bg-bg-primary">
    @php
        $isCorrectivo = (int) $asignacion->tipo === \App\Models\Asignacion::TIPO_CORRECTIVO;
        $totalSteps = $isCorrectivo ? 4 : 2;
        $piv = $asignacion->averia?->piv;
    @endphp

    {{-- Header con back + indicator de paso --}}
    <header class="bg-layer-0 border-b border-default px-4 py-3 flex items-center justify-between">
        <button wire:click="prev"
                class="px-3 py-3 text-sm font-medium {{ $step === 1 ? 'opacity-30 pointer-events-none' : '' }}"
                aria-label="Atrás">
            ← Atrás
        </button>
        <div class="text-xs text-ink-secondary">
            Paso {{ $step }} de {{ $totalSteps }}
        </div>
        @if ($piv)
            <div class="text-xs text-ink-secondary font-mono truncate max-w-[120px]">
                #{{ str_pad((string) $piv->piv_id, 3, '0', STR_PAD_LEFT) }}
            </div>
        @endif
    </header>

    <main class="p-4">
        @if ($isCorrectivo)
            @include('livewire.tecnico.cierre-correctivo-steps', compact('step', 'piv'))
        @else
            @include('livewire.tecnico.cierre-revision-steps', compact('step', 'piv'))
        @endif

        @error('cerrar')
            <p class="text-error text-md font-medium mt-4 text-center">{{ $message }}</p>
        @enderror
    </main>
</div>
```

Crear partials para cada flow:

`resources/views/livewire/tecnico/cierre-correctivo-steps.blade.php` con switches por `$step`:

- **Step 1** — "¿Qué pasó?": 3 botones full-width 96px alto color-coded:
  - 🟢 REPARADO (`wire:click="setEstadoFinal('reparado')"`)
  - 🟡 PENDIENTE 2ª VISITA (`wire:click="setEstadoFinal('pendiente')"`)
  - 🔴 NO REPARABLE (`wire:click="setEstadoFinal('no_reparable')"`)
- **Step 2** — "¿Qué cambiaste?": grid 1-col con los 8 recambios + "Otro" toggle. Cada uno es un botón con check visual + tap target 64px. Botón "Siguiente →" sticky bottom.
- **Step 3** — "¿Cuánto tiempo?": grid 2-col con los 6 botones preset + 1 botón "+más" full-width. Tap auto-advance.
- **Step 4** — "Confirmar": resumen visual de las 3 selecciones, botón cámara gigante, preview fotos, textarea opcional para notas con botón "🎤 Dictar" que abre Web Speech API, botón "✅ CERRAR ASIGNACIÓN" full-width 96px alto.

`resources/views/livewire/tecnico/cierre-revision-steps.blade.php`:

- **Step 1** — Checklist: 6 items, cada uno con 3 botones grandes [OK] [KO] [N/A] preseleccionados a OK. `wire:click="setRevisionItem('aspecto', 'KO')"` etc. Botón "Siguiente →" sticky bottom.
- **Step 2** — Confirmar: resumen del checklist (solo destacar items KO/N/A en color), botón cámara opcional, textarea notas opcional con dictar, botón cerrar gigante.

Para el dictado por voz, en step 4 (correctivo) y step 2 (revisión):

```html
<button type="button"
        x-data="{
            recording: false,
            recognition: null,
            init() {
                if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
                    const Klass = window.SpeechRecognition || window.webkitSpeechRecognition;
                    this.recognition = new Klass();
                    this.recognition.lang = 'es-ES';
                    this.recognition.interimResults = false;
                    this.recognition.continuous = false;
                    this.recognition.onresult = (e) => {
                        const text = Array.from(e.results).map(r => r[0].transcript).join(' ');
                        $wire.set('notas', ($wire.notas ? $wire.notas + ' ' : '') + text);
                    };
                    this.recognition.onend = () => { this.recording = false; };
                }
            },
            toggle() {
                if (!this.recognition) {
                    alert('Tu navegador no soporta dictado por voz. Escribe a mano.');
                    return;
                }
                if (this.recording) {
                    this.recognition.stop();
                } else {
                    this.recognition.start();
                    this.recording = true;
                }
            }
        }"
        @click="toggle"
        :class="recording ? 'bg-error text-ink-on_color' : 'bg-layer-1 text-ink-primary'"
        class="w-full py-3 px-4 text-md font-medium border border-default flex items-center justify-center gap-2">
    <span x-show="!recording">🎤 Dictar nota</span>
    <span x-show="recording">⏹ Detener (grabando...)</span>
</button>
```

(Alpine.js viene con Livewire ya. Si no estuviera disponible, usar `<script>` plain sin x-data.)

### Step 5 — Tests obligatorios

Crear `tests/Feature/Tecnico/Bloque11dCierreFieldgradeTest.php`. Mínimo:

1. `dashboard_renders_card_with_panel_thumbnail_when_lv_correctivo_imagen_exists` — crea Correctivo + LvCorrectivoImagen, verifica `<img src=>` apunta al storage local.
2. `dashboard_falls_back_to_legacy_piv_imagen_when_no_correctivo_image` — verifica src apunta a `winfin.es/images/piv/`.
3. `dashboard_shows_emoji_placeholder_when_panel_has_no_image` — assert no `<img>` tag para el panel limpio.
4. `correctivo_step_1_advances_after_tap_on_estado_final` — `Volt::test`, click `setEstadoFinal('reparado')`, assert `$step === 2`.
5. `correctivo_step_2_recambio_toggle_adds_and_removes` — toggle 'Conector', assert array; toggle again, assert removed.
6. `correctivo_step_3_set_tiempo_advances_to_4` — click `setTiempo('30')`, assert `$step === 4` y `tiempoMinutos === '30'`.
7. `correctivo_full_flow_creates_correctivo_with_normalized_data` — Volt test que va step 1→2→3→4 y submit, verifica que `Correctivo::diagnostico` empieza por "Reparado", `recambios` contiene los items concatenados, `tiempo` está en horas decimales, `lv_correctivo_imagen` row creada.
8. `revision_step_1_setRevisionItem_changes_field_value` — assert `$aspecto` cambia a 'KO' tras click.
9. `revision_full_flow_creates_revision_with_checklist` — Volt test 2 steps + submit, verifica Revision row con campos correctos.
10. `prev_button_decrements_step_but_not_below_1` — click prev en step 1, assert sigue en 1.
11. `tecnico_a_cannot_view_tecnico_b_asignacion` (sigue del 11b — no se rompe).
12. `session_lifetime_is_90_days` — assert `config('session.lifetime') === 129600`.
13. `piv_current_photo_url_uses_latest_correctivo_image_when_available`.
14. `piv_current_photo_url_falls_back_to_legacy_thumbnail_when_no_correctivo`.

Total ~14 tests nuevos. Suite 181 → ~195 verde.

**Tests pivots banderazo rojo**:
- Si Copilot dice "no puedo testear `wire:click` desde Volt::test" → fail. Sí se puede via `->call('setEstadoFinal', 'reparado')`.
- Si dice "Web Speech API es imposible de testear" → OK skip ese test, documenta smoke manual con voz.
- Si dice "el polling 30s no se puede testear" → OK skip, documenta smoke manual.
- Si "skipé el test de boundary porque ya está en 11b" → fail. Hay que reverificar tras refactor del component.

### Step 6 — Smoke real

Pre-requisitos: técnico smoke `id=66` actualmente status=0. Reactivar igual que en smoke 11b. Crear stub asignación tipo=1 + averia. (Mismo patrón create→smoke→cleanup.)

Smoke específico de UX field-grade:

1. Login PWA con técnico smoke. Verificar sesión persiste tras cerrar y reabrir el navegador (90 días lifetime).
2. Dashboard:
   - Saludo "Hola [nombre]" arriba.
   - Botón ⏏ Salir grande arriba derecha.
   - Card con foto del panel (la que tenga `current_photo_url`), kicker color-coded, panel #ID, dirección.
   - Si no hay foto → emoji 📷 placeholder.
   - Tap target completo de la card es ≥ 96px.
3. Tap card → step 1 cierre. Tres botones color-coded gigantes.
4. Tap "REPARADO" → auto-advance a step 2 sin botón "Siguiente".
5. Step 2 recambios: 8 checkboxes. Marcar "Conector" + "Cable". Click "Siguiente".
6. Step 3 tiempo: 7 botones grid. Tap "30 min" → auto-advance.
7. Step 4 confirmar: ver resumen ("Reparado / Conector, Cable / 30 min"). Tap botón cámara → cámara nativa abre. Capturar foto.
8. Tap botón "🎤 Dictar nota" (si Safari permite voice — en desktop pedirá permiso de micrófono). Decir algo como "fallo en alimentación rectificado". Verificar que aparece en el textarea.
9. Tap "✅ CERRAR ASIGNACIÓN".
10. Redirect a dashboard con flash success. Card desaparece.
11. Cleanup: borrar correctivo + lv_correctivo_imagen + asignacion + averia stubs. Re-desactivar técnico 66.
12. Verificar BD post-cleanup limpia.

Captura screenshots de cada step (1-4 correctivo, 1-2 revisión, dashboard) en `docs/runbooks/screenshots/11d-smoke/`.

### Step 7 — Restricciones de proceso (CLAUDE.md)

- Branch: `bloque-11d-pwa-tecnico-fieldgrade-ux`.
- Commits atómicos esperados:
  1. `feat(session): extend tecnico session lifetime to 90 days`
  2. `feat(piv): add current_photo_url accessor for fresh dashboard thumbnails`
  3. `feat(tecnico): redesign dashboard with photo cards + polling 30s`
  4. `feat(tecnico): refactor cierre as 4-step Glovo-pattern flow`
  5. `feat(tecnico): add voice dictation button via Web Speech API`
  6. `feat(tecnico): add panel placeholder svg`
  7. `test(tecnico): cover fieldgrade UX flow + photo accessor`
- Push a la rama, PR contra `main`. NO mergear: el usuario revisa y mergea.
- NO modificar `app/Services/`, `app/Filament/`, `app/Auth/`, `app/Models/User.php`, `lang/`, `config/auth.php`.
- **NO** fix de las deudas del 11ab/11b (race condition, kebab clip, throttle UX, sidebar overlay) — son chips spawned post-merge.

## Reporte final

Al terminar, devolver:

```
## Bloque 11d — Reporte

### Commits
- <hash> feat(session): extend tecnico session lifetime to 90 days
- <hash> feat(piv): add current_photo_url accessor for fresh dashboard thumbnails
- <hash> feat(tecnico): redesign dashboard with photo cards + polling 30s
- <hash> feat(tecnico): refactor cierre as 4-step Glovo-pattern flow
- <hash> feat(tecnico): add voice dictation button via Web Speech API
- <hash> feat(tecnico): add panel placeholder svg
- <hash> test(tecnico): cover fieldgrade UX flow + photo accessor

### Tests
- Suite total: 181 → ~195 verde.
- 4 jobs CI verde.

### Smoke pendiente al merge
- Reactivar técnico 66 + crear stubs + ejecutar smoke 12 puntos + cleanup.

### Pivots realizados (si los hubo)
- ...

### Riesgos conocidos
- Web Speech API solo disponible con HTTPS en producción real (en `127.0.0.1` Safari permite, otros browsers pueden requerir banderas). En SiteGround prod ya habrá HTTPS, no problema.
- Dictado en español requiere voz online (no offline). En zonas sin cobertura no funciona — degradación elegante: input texto sigue disponible.
- (otros)

### Deudas que NO se atacan en este bloque
- tecnico_id race condition (chip).
- Kebab dropdown clipping (chip).
- PWA throttle message UX (chip).
- Sidebar overlay viewport estrecho (Copilot 11ab Parte C).
- Service Worker + offline + iconos definitivos (Bloque 11c, planeado).
- Push notifications real (Bloque 13).
- Migración `lv_recambio_catalog` editable desde admin (cuando los recambios precargados crezcan más allá de 8-10).
```
