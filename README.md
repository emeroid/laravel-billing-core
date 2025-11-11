$user = auth()->user();

// Check if a user has an 'active' subscription
if ($user->isSubscribed()) {
    // ...
}

// Check if a user has cancelled but their period is still active
if ($user->onGracePeriod()) {
    // ...
}

// **This is the most common check you will use:**
// Check if user should have access (either active or on grace period)
if ($user->hasActiveSubscription()) {
    // Show them paid features
}

// Check if user is on a *specific* plan
if ($user->isSubscribedTo('pro-plan')) {
    // Show them "Pro" features
}

// Check if a payment has failed
if ($user->isPastDue()) {
    // Show a banner: "Please update your payment method."
}

// --- Management ---

// Get a specific subscription
$subscription = $user->getSubscription('SUB_abc'); // Gateway Subscription ID

// Cancel an active subscription (with grace period)
if ($subscription) {
    // This automatically finds the end of their billing period
    // and cancels the sub, but keeps their access until that date.
    $user->cancelSubscription($subscription->gateway_subscription_id);
}

// Swap a plan
// This handles proration and gateway API calls
$user->swapPlan($subscription->gateway_subscription_id, 'new-pro-plan-slug');
```

### 7\. Listening for Events

This package fires events. You can listen for them in your `EventServiceProvider`:

```php
use Emeroid\Billing\Events\TransactionSuccessful;
use Emeroid\Billing\Events\SubscriptionStarted;
use Emeroid\Billing\Events\SubscriptionCancelled;

protected $listen = [
    TransactionSuccessful::class => [
        'App\Listeners\GrantAccessToProduct',
        'App\Listeners\SendInvoiceEmail',
    ],
    SubscriptionStarted::class => [
        'App\Listeners\ActivateProFeatures',
    ],
    SubscriptionCancelled::class => [
        'App\Listeners\RevokeProFeatures',
    ],
    SubscriptionPlanSwapped::class => [
        'App\Listeners\HandlePlanSwap',
    ],
    SubscriptionPaymentFailed::class => [
        'App\Listeners\SendDunningEmail',
    ],
];
```

## Contributing
// ... existing code ... -->
```