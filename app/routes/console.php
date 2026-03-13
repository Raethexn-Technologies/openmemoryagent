<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Apply Physarum decay to memory graph edges once per day.
// Edges that were not traversed during the preceding day lose 3 % of their weight.
// See MemoryGraphService::decay() and DEVLOG Entry 003 for the mathematical basis.
Schedule::command('memory:decay')->daily();
