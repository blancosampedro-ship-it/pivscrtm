<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AveriaIccaImportService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Throwable;

final class ImportAveriaIcca extends Command
{
    protected $signature = 'lv:import-averia-icca
                            {file : Path absoluto al CSV}
                            {--user=1 : ID admin lv_users}';

    protected $description = 'Importa CSV SGIP/ICCA a lv_averia_icca con política ADD + mark inactive.';

    public function handle(AveriaIccaImportService $service): int
    {
        $path = (string) $this->argument('file');
        $userId = (int) $this->option('user');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::INVALID;
        }

        $admin = User::findOrFail($userId);
        $upload = new UploadedFile($path, basename($path), 'text/csv', null, true);
        try {
            $preview = $service->preview($upload);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $this->table(
            ['Metrica', 'Valor'],
            collect($preview)
                ->map(fn (mixed $value, string $key): array => [$key, is_array($value) ? count($value) : $value])
                ->values()
                ->all(),
        );

        if (! $this->confirm("Aplicar import (mark inactive {$preview['would_mark_inactive']})?")) {
            return self::SUCCESS;
        }

        try {
            $result = $service->import($upload, $admin);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Created {$result['created']} · Updated {$result['updated']} · Marked inactive {$result['marked_inactive']}");

        return self::SUCCESS;
    }
}
