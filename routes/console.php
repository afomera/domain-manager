<?php

use Illuminate\Support\Facades\Schedule;
use Laravel\Telescope\Telescope;

Schedule::command('cloudflare:sync')->hourly()->withoutOverlapping();

// Telescope only exists locally (it's a dev dependency).
if (class_exists(Telescope::class)) {
    Schedule::command('telescope:prune --hours=48')->daily();
}
