<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// subscriptions:expire is registered as its own Command class in
// app/Console/Commands (see Phase 10) and auto-discovered by Laravel —
// no need to require it here.
