Laravel Billing Core is a robust, driver-based, multi-gateway billing package for Laravel. It provides a simple, fluent API to manage one-time payments, subscriptions, plan swapping, and dunning logic for your SaaS application.

Stop rebuilding billing logic for every project. This package is the plug-and-play foundation you need.

Features

Driver-Based: Switch gateways just by changing your .env.

Multi-Gateway: Out-of-the-box support for Paystack and PayPal.

Subscription Management: A complete subscription lifecycle (subscribe, cancel, swapPlan).

Grace Periods: Automatically handles "cancel on end of billing period."

Plan Swapping: Fluent API to upgrade or downgrade users.

Dunning: Listens for invoice.payment_failed webhooks to set past_due status.

Event-Based: Fires events like SubscriptionStarted, SubscriptionCancelled, etc., so your app can react.

Billable Trait: A powerful interface to add to your User model.

Installation

composer require emeroid/laravel-billing-core


1. Configuration

Publish the Config File:
This will create config/billing.php.

php artisan vendor:publish --provider="Emeroid\Billing\BillingServiceProvider" --tag="billing-config"


Publish the Migrations:
This will add the plans, subscriptions, and transactions tables.

php artisan vendor:publish --provider="Emeroid\Billing\BillingServiceProvider" --tag="billing-migrations"


Run the Migrations:

php artisan migrate


Update Your .env File:
Add your gateway keys and settings.

# --- BILLING CORE ---
BILLING_DEFAULT_DRIVER=paystack
BILLING_MODEL=\App\Models\User

# --- PAYSTACK ---
PAYSTACK_PUBLIC_KEY=pk_...
PAYSTACK_SECRET_KEY=sk_...

# --- PAYPAL ---
PAYPAL_CLIENT_ID=...
PAYPAL_SECRET=...
PAYPAL_MODE=sandbox
PAYPAL_WEBHOOK_ID=WH-...


Add the Billable Trait:
Add the Emeroid\Billing\Traits\Billable trait to your User model (or any model you defined in billing.model).

// app/Models/User.php

use Emeroid\Billing\Traits\Billable;
use Illuminate{...};

class User extends Authenticatable
{
    use Billable, HasFactory, Notifiable;
    // ...
}


2. Usage: Creating Plans

Before you can create subscriptions, you must define your plans in the plans table. You can do this in a seeder.

// database/seeders/PlanSeeder.php

use Emeroid\Billing\Models\Plan;
use Illuminate.Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'amount' => 500000, // 5000 NGN (in kobo)
            'interval' => 'monthly',
            'paystack_plan_id' => 'PL_abc123', // ID from your Paystack dashboard
            'paypal_plan_id' => 'P-xyz456',    // ID from your PayPal dashboard
        ]);
        
        Plan::create([
            'name' => 'Business Plan',
            'slug' => 'business-plan',
            'amount' => 1000000, // 10000 NGN
            'interval' => 'monthly',
            'paystack_plan_id' => 'PL_def789',
            'paypal_plan_id' => 'P-ghi123',
        ]);
    }
}


3. Usage: One-Time Payments

Use the Billing facade to initiate a payment.

use Emeroid\Billing\Facades\Billing;
use Illuminate\Http\Request;

class PaymentController
{
    public function startPayment(Request $request)
    {
        $user = $request->user();
        $amountInKobo = 50000; // 5000 NGN
        
        try {
            // Initiate the payment
            $payment = Billing::purchase($amountInKobo, $user->email, [
                'user_id' => $user->id,
                'currency' => 'NGN',
            ]);

            // $payment = ['authorization_url' => '...', 'reference' => '...']

            // Redirect to Paystack/PayPal
            return redirect()->away($payment['authorization_url']);
            
        } catch (\Emeroid\Billing\Exceptions\PaymentInitializationFailedException $e) {
            // Handle the error
            return back()->with('error', $e->getMessage());
        }
    }
}


4. Usage: Subscriptions

Subscribing a user is just as easy. You just need the gateway plan ID from your plans table.

use Emeroid\Billing\Facades\Billing;
use Emeroid\Billing\Models\Plan;
use Illuminate\Http\Request;

class SubscriptionController
{
    public function startSubscription(Request $request)
    {
        $user = $request->user();
        $plan = Plan::where('slug', 'pro-plan')->firstOrFail();
        
        // Get the correct gateway ID based on the default driver
        $gatewayPlanId = config('billing.default') === 'paypal'
            ? $plan->paypal_plan_id
            : $plan->paystack_plan_id;

        try {
            // Initiate the subscription
            $subscription = Billing::subscribe(
                $gatewayPlanId, 
                $user->email, 
                [
                    'amount' => $plan->amount, // Required for Paystack's first charge
                    'user_id' => $user->id,
                    'currency' => 'NGN',
                ]
            );

            // $subscription = ['authorization_url' => '...', 'reference' => '...']

            // Redirect to Paystack/PayPal
            return redirect()->away($subscription['authorization_url']);
            
        } catch (\Emeroid\Billing\Exceptions\PaymentInitializationFailedException $e) {
            // Handle the error
            return back()->with('error', $e->getMessage());
        }
    }
}


5. Handling Callbacks (After Payment)

After a user pays, they are redirected back to your site. This package provides a built-in controller to handle this, verify the payment, and redirect to a success/failure page.

This is handled automatically by the package.

The CallbackController will:

Verify the transaction (Billing::verifyTransaction(...)).

If it's a subscription, create the Subscription record in your database.

Fire TransactionSuccessful or SubscriptionStarted events.

Redirect to the success or failure URL set in config/billing.php.

6. Handling Webhooks (Server-to-Server)

Webhooks are critical for keeping your database in sync. The package provides webhook routes:

https://your-app.com/billing-webhooks/paystack

https://your-app.com/billing-webhooks/paypal

You must add these URLs to your Paystack and PayPal dashboards.

The package automatically listens for:

charge.success: To verify payments.

subscription.create: To create subscriptions.

subscription.disable: To trigger SubscriptionCancelled.

Dunning: invoice.payment_failed (Paystack) or BILLING.SUBSCRIPTION.PAYMENT.FAILED (PayPal) to set the subscription status to past_due.

7. The Billable Trait API

This is how you will manage users in your application. All methods are available on your User model.

$user = auth()->user();

// --- STATUS CHECKS ---

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

// --- MANAGEMENT ---

// Get a specific subscription
$subscription = $user->getSubscription('SUB_abc'); // Use the Gateway's Subscription ID

// Cancel an active subscription (with grace period)
if ($subscription) {
    // This automatically finds the end of their billing period
    // and cancels the sub, but keeps their access until that date.
    $user->cancelSubscription($subscription->gateway_subscription_id);
}

// Swap a plan
// This handles proration and gateway API calls
$user->swapPlan($subscription->gateway_subscription_id, 'business-plan'); // Use the new plan's slug

// Sync subscription status from the gateway
$user->syncSubscription($subscription->gateway_subscription_id);


8. Events

Your app can react to billing events by listening for them in your EventServiceProvider.

// app/Providers/EventServiceProvider.php

use Emeroid\Billing\Events\TransactionSuccessful;
use Emeroid\Billing\Events\SubscriptionStarted;
use Emeroid\Billing\Events\SubscriptionCancelled;
use Emeroid\Billing\Events\SubscriptionPlanSwapped;
use Emeroid\Billing\Events\SubscriptionPaymentFailed;

protected $listen = [
    TransactionSuccessful::class => [
        'App\Listeners\GrantAccessToProduct',
        'App\Listeners\SendInvoiceEmail',
    ],
    SubscriptionStarted::class => [
        'App\Listeners\ActivateProFeatures',
    ],
    SubscriptionCancelled::class => [
        'App\Listeners\RevokeProFeaturesAtPeriodEnd',
    ],
    SubscriptionPlanSwapped::class => [
        'App\Listeners\HandlePlanSwap',
    ],
    SubscriptionPaymentFailed::class => [
        'App\Listeners\SendDunningEmail',
    ],
];


Testing

You can run the package's built-in test suite:

composer test


Contributing

Please see CONTRIBUTING.md for details.

Security

If you discover any security-related issues, please email threalyongbug@gmail.com instead of using the issue tracker.

License

The MIT License (MIT). Please see License File for more information.

❤️ Sponsorship

This project is free and open-source. If it helps you build your business, please consider supporting its development.

Sponsor @emeroid on GitHub