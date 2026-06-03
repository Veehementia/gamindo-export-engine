<?php

namespace App\Console;

use App\Console\Commands\SeedDemoData;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        SeedDemoData::class,
    ];

    protected function schedule(Schedule $schedule)
    {
        // Spazio per task pianificati (es. pulizia file export vecchi).
    }
}
