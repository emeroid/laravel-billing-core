<?php

namespace Emeroid\Billing\Traits;

use Emeroid\Billing\Facades\Billing;
use Emeroid\Billing\Models\Plan;
use Emeroid\Billing\Models\Subscription;
use Emeroid\Billing\Models\Transaction;

trait Billable
{
    public function transactions()
    {
        return $this->hasMany(Transaction::class)->orderBy('created_at', 'desc');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class)->orderBy('created_at', 'desc');
    }

    /**
     * Check if the user has an active subscription.
     * This does *not* include subscriptions on a grace period.
     */
    public function isSubscribed(): bool
    {
        return $this->subscriptions()->where('status', 'active')->exists();
    }

    /**
     * Check if the user is subscribed to a specific plan.
     *
     * @param string $planSlug The slug of the plan in your `plans` table.
     */
    public function isSubscribedTo(string $planSlug): bool
    {
        return $this->subscriptions()
            ->where('status', 'active')
            ->whereHas('plan', fn($query) => $query->where('slug', $planSlug))
            ->exists();
    }

    /**
     * Check if the user has a subscription that is "active"
     * (meaning, they should have access), including those on a grace period.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->isSubscribed() || $this->onGracePeriod();
    }
    
    /**
     * Check if the user has cancelled but is still in their paid-for period.
     */
    public function onGracePeriod(): bool
    {
        return $this->subscriptions()
            ->where('status', 'cancelled')
            ->where('ends_at', '>', now())
            ->exists();
    }

    /**
     * Check if the user's subscription payment has failed.
     */
    public function isPastDue(): bool
    {
        return $this->subscriptions()->where('status', 'past_due')->exists();
    }

    /**
     * Get a specific subscription by its gateway ID.
     */
    public function getSubscription(string $gatewaySubscriptionId): ?Subscription
    {
        return $this->subscriptions()->where('gateway_subscription_id', $gatewaySubscriptionId)->first();
    }

    /**
     * Cancel an active subscription.
     * This will automatically set the `ends_at` to the end of the billing period.
     *
     * @param string $gatewaySubscriptionId
     * @param string $reason
     * @return Subscription|null
     */
    public function cancelSubscription(string $gatewaySubscriptionId, string $reason = 'Cancelled by user'): ?Subscription
    {
        $subscription = $this->getSubscription($gatewaySubscriptionId);

        if ($subscription && $subscription->status === 'active') {
            return Billing::cancelSubscription($subscription, $reason);
        }

        return $subscription;
    }

    /**
     * Swaps a user's subscription to a new plan.
     *
     * @param string $gatewaySubscriptionId The ID of the subscription to swap.
     * @param string $newPlanSlug The slug (from your `plans` table) of the new plan.
     * @return Subscription|null
     */
    public function swapPlan(string $gatewaySubscriptionId, string $newPlanSlug): ?Subscription
    {
        $subscription = $this->getSubscription($gatewaySubscriptionId);
        
        $newPlan = Plan::where('slug', $newPlanSlug)->firstOrFail();

        if ($subscription && $subscription->status === 'active') {
            $oldPlan = $subscription->plan;

            $updatedSubscription = Billing::swapPlan($subscription, $newPlan);
            
            event(new \Emeroid\Billing\Events\SubscriptionPlanSwapped($updatedSubscription, $oldPlan));
            
            return $updatedSubscription;
        }

        return $subscription;
    }
    
    /**
     * Sync subscription status from the gateway.
     */
    public function syncSubscription(string $gatewaySubscriptionId): ?Subscription
    {
        $subscription = $this->getSubscription($gatewaySubscriptionId);

        if ($subscription) {
            return Billing::getSubscriptionDetails($subscription);
        }

        return null;
    }
}