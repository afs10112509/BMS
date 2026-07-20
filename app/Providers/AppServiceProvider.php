<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use App\Models\Transaction;
use App\Models\WorkshopJob;
use App\Policies\TransactionPolicy;
use App\Policies\WorkshopJobPolicy;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(WorkshopJob::class, WorkshopJobPolicy::class);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
