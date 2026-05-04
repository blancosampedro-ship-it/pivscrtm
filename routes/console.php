<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('lv:generate-revision-pendiente-monthly')
    ->monthlyOn(1, '06:00')
    ->timezone('Europe/Madrid')
    ->onOneServer()
    ->name('lv-generate-revision-pendiente-monthly');

Schedule::command('lv:promote-revisiones-to-asignacion')
    ->dailyAt('06:00')
    ->timezone('Europe/Madrid')
    ->onOneServer()
    ->name('lv-promote-revisiones-to-asignacion');
