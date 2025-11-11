<?php

namespace Emeroid\Billing\Facades;

use Illuminate\Support\Facades\Facade;
use Emeroid\Billing\Contracts\GatewayContract;

/**
 * @method static \Emeroid\Billing\Contracts\GatewayContract driver(string $driver)
 * @method static array purchase(int $amount, string $email, array $options = [])
 * @method static array subscribe(string $planId, string $email, array $options = [])
 * @method static \Emeroid\Billing\Models\Transaction verifyTransaction(string $reference)
 * * @see \Emeroid\Billing\BillingManager
 */
class Billing extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        // This matches the alias we set in the ServiceProvider
        return GatewayContract::class;
    }
}
