<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\LvAveriaIccaResource;
use App\Services\AveriaIccaImportService;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ImportarSgip extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Averías';

    protected static ?string $navigationLabel = 'Importar SGIP';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-up';

    protected static ?string $slug = 'importar-sgip';

    protected static string $view = 'filament.pages.importar-sgip';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $previewResult = null;

    public function mount(): void
    {
        $this->form->fill([
            'confirm_snapshot' => false,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('csv')
                    ->label('CSV SGIP exportado')
                    ->acceptedFileTypes(['text/csv', 'application/csv', 'text/plain'])
                    ->maxSize(5120)
                    ->disk('local')
                    ->directory('imports/sgip')
                    ->preserveFilenames(false)
                    ->required(),
                Checkbox::make('confirm_snapshot')
                    ->label('Confirmo que este CSV es foto completa SGIP/ICCA de Winfin')
                    ->helperText('Al importar, las averías activas ausentes en el CSV se marcarán como inactivas.'),
            ])
            ->statePath('data');
    }

    public function preview(): void
    {
        $path = $this->csvPath();

        if ($path === null) {
            Notification::make()->title('Sube un CSV primero')->danger()->send();

            return;
        }

        try {
            $upload = $this->uploadedFileFromStoredPath($path);
            $this->previewResult = app(AveriaIccaImportService::class)->preview($upload);
        } catch (Throwable $exception) {
            Notification::make()->title('CSV no importable')->body($exception->getMessage())->danger()->send();
        }
    }

    public function confirm(): void
    {
        $path = $this->csvPath();

        if ($path === null || $this->previewResult === null) {
            Notification::make()->title('Genera el preview primero')->danger()->send();

            return;
        }

        if (($this->data['confirm_snapshot'] ?? false) !== true) {
            Notification::make()->title('Marca la confirmación de foto completa')->danger()->send();

            return;
        }

        try {
            $result = app(AveriaIccaImportService::class)->import(
                $this->uploadedFileFromStoredPath($path),
                auth()->user(),
            );
        } catch (Throwable $exception) {
            Notification::make()->title('Import fallido')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Import OK')
            ->body("Created {$result['created']} · Updated {$result['updated']} · Marked inactive {$result['marked_inactive']}")
            ->success()
            ->send();

        $this->previewResult = null;
        $this->form->fill([
            'confirm_snapshot' => false,
        ]);
        $this->redirect(LvAveriaIccaResource::getUrl('index'));
    }

    private function csvPath(): ?string
    {
        $path = $this->data['csv'] ?? null;

        if (is_array($path)) {
            $path = reset($path) ?: null;
        }

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function uploadedFileFromStoredPath(string $path): UploadedFile
    {
        $absolutePath = Storage::disk('local')->path($path);

        return new UploadedFile($absolutePath, basename($absolutePath), 'text/csv', null, true);
    }
}
