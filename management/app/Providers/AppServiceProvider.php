<?php

namespace App\Providers;

use App\Models\License;
use App\Models\LicenseActivationKey;
use App\Models\RouterNode;
use App\Policies\LicensePolicy;
use App\Policies\RouterNodePolicy;
use App\Services\RouterAgentClient;
use App\Support\DestructiveMigrationGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RouterAgentClient::class, function (): RouterAgentClient {
            return new RouterAgentClient(config('nurrouteros.agent_socket'));
        });
    }

    public function boot(): void
    {
        Gate::policy(RouterNode::class, RouterNodePolicy::class);
        Gate::policy(License::class, LicensePolicy::class);
        Gate::policy(LicenseActivationKey::class, LicensePolicy::class);

        RateLimiter::for('licensing-activation', function (Request $request) {
            $perMinute = max(1, (int) config('licensing.activation_rate_limit_per_minute', 10));

            return Limit::perMinute($perMinute)->by(Str::transliterate(
                Str::lower((string) $request->user()?->getAuthIdentifier()).'|'.$request->ip(),
            ));
        });

        Event::listen(function (CommandStarting $event): void {
            app(DestructiveMigrationGuard::class)->ensureAllowed($event->command);
        });
    }
}
