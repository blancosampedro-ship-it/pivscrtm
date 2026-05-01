<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Desactiva Vite en tests (Bloque 05): Filament intenta resolver el manifest
     * de `public/build/manifest.json`, que en CI no existe cuando el job PHP
     * corre antes que el job de Vite. `withoutVite()` reemplaza el helper @vite
     * por un no-op solo durante los tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
