<?php

namespace Emeroid\Billing\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Auth\User;
use Orchestra\Testbench\TestCase as Orchestra;
use Emeroid\Billing\BillingServiceProvider;
use Emeroid\Billing\Facades\Billing;

class TestCase extends Orchestra
{
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup the database
        $this->setupDatabase();
        
        // Create a test user
        $this->user = TestUser::create(['email' => 'test@user.com', 'name' => 'Test User', 'password' => '1234']);

        // No need for Factory::guess...
    }

    protected function getPackageProviders($app)
    {
        return [
            BillingServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Billing' => Billing::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        // Use an in-memory sqlite database
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('database.prefix', '');

        // Set the billable model for testing
        $app['config']->set('billing.model', \Emeroid\Billing\Tests\TestUser::class);
        
        // Set test API keys
        $app['config']->set('billing.drivers.paystack.secret_key', 'sk_test_123');
        $app['config']->set('billing.drivers.paypal.client_id', 'paypal_client_id');
        $app['config']->set('billing.drivers.paypal.secret', 'paypal_secret');
        $app['config']->set('billing.drivers.paypal.mode', 'sandbox');
        $app['config']->set('billing.drivers.paypal.webhook_id', 'paypal_webhook_id');

        // Set test routes for callbacks
        $app['config']->set('billing.redirects.success', '/success');
        $app['config']->set('billing.redirects.failure', '/failure');
    }

    protected function setupDatabase()
    {
        // Create a minimal users table for testing
        // This must run *before* the package migration
        $this->app['db']->schema()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        // Run your package migrations
        $migration = include __DIR__.'/../database/migrations/2025_10_28_000000_create_billing_tables.php';
        $migration->up();
    }
}