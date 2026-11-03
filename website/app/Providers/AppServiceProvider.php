<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $compiledPath = config('view.compiled');
        if (is_string($compiledPath) && is_dir($compiledPath)) {
            $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
            foreach (glob($compiledPath.'/*.php') ?: [] as $compiledFile) {
                if (! is_writable($compiledFile) || ($euid !== null && @fileowner($compiledFile) !== $euid)) {
                    @unlink($compiledFile);
                }
            }
        }
    }
}
