<?php

use Illuminate\Support\Facades\Schedule;

// Daily billing at 02:15 IST. Server cron: * * * * * php /path/to/artisan schedule:run
Schedule::command('platform:billing-run')->dailyAt('02:15')->timezone('Asia/Kolkata')->withoutOverlapping()->onOneServer();

// Fees at 06:30 IST: issue invoices that have come due, late fees, advances and reminders.
Schedule::command('fees:daily')->dailyAt('06:30')->timezone('Asia/Kolkata')->withoutOverlapping()->onOneServer();
