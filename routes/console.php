<?php

use App\Console\Commands\SyncGenerations;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command(SyncGenerations::class)->everyMinute()->withoutOverlapping();

Artisan::command('app:make-admin {email}', function (string $email) {
    $updated = User::query()->where('email', $email)->update(['is_admin' => true]);

    $updated ? $this->info("{$email} is now an admin.") : $this->error("No user with email {$email}.");
})->purpose('Give a user access to the admin report');
