<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Guardián contra los duplicados que genera iCloud/Finder ("fichero 2.php").
 *
 * Este repo vive en ~/Documents (sincronizado por iCloud), que de vez en
 * cuando materializa copias "X 2.php". Si el duplicado cae en
 * database/migrations/ el migrador ejecuta AMBAS migraciones y revienta toda
 * la suite (pasó el 2026-07-19); en app/ declara clases repetidas. Este test
 * lo caza al momento con un mensaje claro.
 */
it('no hay duplicados de iCloud ("* 2.php") en directorios de código', function (): void {
    $finder = Finder::create()
        ->files()
        ->in([app_path(), database_path('migrations'), config_path(), resource_path('views')])
        ->name('* 2.*');

    $duplicados = [];
    foreach ($finder as $file) {
        $duplicados[] = $file->getRelativePathname();
    }

    expect($duplicados)->toBe([], 'Duplicados de iCloud detectados (bórralos): '.implode(', ', $duplicados));
});
