<?php

namespace Emeroid\Billing;

use Illuminate\Support\Manager;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Emeroid\Billing\Contracts\GatewayContract;
use Emeroid\Billing\Drivers\PaystackDriver;
use Emeroid\Billing\Drivers\PaypalDriver;
use Emeroid\Billing\Models\Plan;
use Emeroid\Billing\Models\Subscription;
use Emeroid\Billing\Models\Transaction;

class BillingManager extends Manager implements GatewayContract
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('billing.default');
    }

    protected function createPaystackDriver(): GatewayContract
    {
        $config = $this->config->get('billing.drivers.paystack');
        
        if (empty($config['secret_key'])) {
            throw new InvalidArgumentException('Paystack secret key is not set.');
        }

        return new PaystackDriver($config);
    }

    protected function createPaypalDriver(): GatewayContract
    {
        $config = $this->config->get('billing.drivers.paypal');
        
        if (empty($config['client_id']) || empty($config['secret'])) {
            throw new InvalidArgumentException('PayPal client_id or secret is not set.');
        }

        return new PaypalDriver($config);
    }

    // --- Pass-through methods to the default driver ---

    public function purchase(int $amount, string $email, array $options = []): array
    {
        return $this->driver()->purchase($amount, $email, $options);
    }

    public function subscribe(string $planId, string $email, array $options = []): array
    {
        return $this->driver()->subscribe($planId, $email, $options);
    }

    public function verifyTransaction(string $reference): Transaction
    {
        return $this->driver()->verifyTransaction($reference);
    }
    
    public function cancelSubscription(Subscription $subscription, string $reason = 'Cancelled by user'): Subscription
    {
        // Delegate to the *correct* driver for this subscription
        return $this->driver($subscription->gateway)->cancelSubscription($subscription, $reason);
    }
    
    public function getSubscriptionDetails(Subscription $subscription): Subscription
    {
        // Delegate to the *correct* driver
        return $this->driver($subscription->gateway)->getSubscriptionDetails($subscription);
    }

    public function swapPlan(Subscription $subscription, Plan $newPlan): Subscription
    {
        // Delegate to the *correct* driver
        return $this->driver($subscription->gateway)->swapPlan($subscription, $newPlan);
    }

    public function handleWebhook(Request $request)
    {
        // This method is not called directly.
        // The WebhookController calls the specific driver's handleWebhook method.
        // e.g., Billing::driver('paystack')->handleWebhook($request);
        // This implementation just satisfies the contract.
        return $this->driver()->handleWebhook($request);
    }
}