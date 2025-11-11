<?php

namespace Emeroid\Billing;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Emeroid\Billing\BillingManager;
use Emeroid\Billing\Contracts\GatewayContract;

class BillingServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge the package config file
        $this->mergeConfigFrom(
            __DIR__.'/../config/billing.php', 'billing'
        );

        // Bind the BillingManager to the service container as a singleton
        // This is the core factory that will create gateway drivers
        $this->app->singleton(GatewayContract::class, function ($app) {
            return new BillingManager($app);
        });

        // Add a simpler alias for facade access
        $this->app->alias(GatewayContract::class, 'billing');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish the configuration file
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/billing.php' => config_path('billing.php'),
            ], 'billing-config');

            // Publish the migration files
            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'billing-migrations');
        }

        // Load the routes for webhooks
        $this->registerRoutes();
    }

    /**
     * Register the package routes (for webhooks).
     */
    protected function registerRoutes(): void
    {
        // Webhook routes (API)
        Route::group($this->webhookRouteConfiguration(), function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        });

        // User-facing callback routes (WEB)
        Route::group($this->callbackRouteConfiguration(), function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/callbacks.php');
        });
    }

    /**
     * Get the webhook route configuration.
     */
    protected function webhookRouteConfiguration(): array
    {
        return [
            'prefix' => config('billing.webhook_prefix', 'billing-webhooks'),
            'middleware' => config('billing.webhook_middleware', 'api'),
        ];
    }

    /**
     * Get the callback route configuration.
     */
    protected function callbackRouteConfiguration(): array
    {
        return [
            'prefix' => config('billing.callback_prefix', 'billing-callback'),
            'middleware' => config('billing.callback_middleware', 'web'),
        ];
    }
}