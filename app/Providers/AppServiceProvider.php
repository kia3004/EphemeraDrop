<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\DB;
use App\Session\PrivacyDatabaseSessionHandler;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Session::extend('database', function ($app) {
            return new PrivacyDatabaseSessionHandler(
                DB::connection(config('session.connection')),
                config('session.table'),
                config('session.lifetime'),
                $app,
            );
        });

        RateLimiter::for('create-drop', function ($request) {
            return Limit::perHour(config('ephemera.rate_limits.create_drop_per_ip'))->by($request->ip());
        });

        RateLimiter::for('claim', function ($request) {
            return [
                Limit::perMinute(config('ephemera.rate_limits.claim_per_ip'))->by('claim-ip:' . $request->ip()),
                Limit::perMinute(config('ephemera.rate_limits.claim_per_ip'))->by('claim-drop:' . ($request->route('id') ?? 'drop')),
            ];
        });

        RateLimiter::for('chunk-upload', function ($request) {
            return Limit::perMinute(600)->by($request->ip());
        });
    }
}
