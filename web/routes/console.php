<?php

use Illuminate\Support\Facades\Schedule;

// Daily billing at 02:15 IST. Server cron: * * * * * php /path/to/artisan schedule:run
Schedule::command('platform:billing-run')->dailyAt('02:15')->timezone('Asia/Kolkata')->withoutOverlapping()->onOneServer();
