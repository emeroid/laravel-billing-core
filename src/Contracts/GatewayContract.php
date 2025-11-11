<?php

namespace Emeroid\Billing\Contracts;

use Illuminate\Http\Request;
use Emeroid\Billing\Models\Plan;
use Emeroid\Billing\Models\Subscription;
use Emeroid\Billing\Models\Transaction;

/**
 * Interface GatewayContract
 * Defines the "rules" that all gateway drivers must follow.
 */
interface GatewayContract
{
    /**
     * Set the driver to be used.
     *
     * @param string|null $driver
     * @return self
     */
    public function driver(?string $driver = null): self;

    /**
     * Initiate a one-time purchase.
     *
     * @param int $amount (in lowest denomination, e.g., kobo/cents)
     * @param string $email
     * @param array $options (metadata, currency, return_url, etc.)
     * @return array (e.g., ['authorization_url' => '...', 'reference' => '...'])
     */
    public function purchase(int $amount, string $email, array $options = []): array;

    /**
     * Initiate a subscription.
     *
     * @param string $planId (The ID of the plan on the gateway's platform)
     * @param string $email
     * @param array $options (metadata, return_url, etc.)
     * @return array (e.g., ['authorization_url' => '...', 'reference' => '...'])
     */
    public function subscribe(string $planId, string $email, array $options = []): array;

    /**
     * Verify a transaction's status.
     *
     * @param string $reference
     * @return Transaction
     */
    public function verifyTransaction(string $reference): Transaction;

    /**
     * Handle an incoming webhook from the gateway.
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function handleWebhook(Request $request);

    /**
     * Cancel a subscription on the gateway.
     *
     * @param Subscription $subscription
     * @param string $reason
     * @return Subscription
     */
    public function cancelSubscription(Subscription $subscription, string $reason = 'Cancelled by user'): Subscription;
    
    /**
     * Syncs the subscription details from the gateway.
     *
     * @param Subscription $subscription
     * @return Subscription
     */
    public function getSubscriptionDetails(Subscription $subscription): Subscription;

    /**
     * Swaps a subscription to a new plan.
     *
     * @param Subscription $subscription
     * @param \Emeroid\Billing\Models\Plan $newPlan
     * @return Subscription
     */
    public function swapPlan(Subscription $subscription, Plan $newPlan): Subscription;
}