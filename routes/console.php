<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled work (run locally with `php artisan schedule:work`).
| Both commands select due items across organizations and then act inside
| each organization's tenant context.
*/
Schedule::command('alerts:evaluate')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('reports:run-scheduled')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('insights:detect-anomalies')->dailyAt('02:00')->withoutOverlapping();
