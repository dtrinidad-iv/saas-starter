<?php

use App\Models\AuditLog;
use App\Models\PlatformAuditLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete audit log entries (organization and platform) older than config('audit.retention_days').
Schedule::command('model:prune', ['--model' => [AuditLog::class, PlatformAuditLog::class]])->daily();
