Here’s a clean, well-formatted **Markdown** version of your README file:

---

# Laravel Billing Core

**Laravel Billing Core** is a robust, driver-based, multi-gateway billing package for Laravel.
It provides a simple, fluent API to manage **one-time payments**, **subscriptions**, **plan swapping**, and **dunning logic** for your SaaS application.

> Stop rebuilding billing logic for every project — this package is the plug-and-play foundation you need.

---

## 🚀 Features

* **Driver-Based:** Switch gateways just by changing your `.env`.
* **Multi-Gateway:** Out-of-the-box support for **Paystack** and **PayPal**.
* **Subscription Management:** A complete subscription lifecycle (`subscribe`, `cancel`, `swapPlan`).
* **Grace Periods:** Automatically handles “cancel on end of billing period”.
* **Plan Swapping:** Fluent API to upgrade or downgrade users.
* **Dunning:** Listens for `invoice.payment_failed` webhooks to set `past_due` status.
* **Event-Based:** Fires events like `SubscriptionStarted`, `SubscriptionCancelled`, etc.
* **Billable Trait:** A powerful interface you can add to your `User` model.

---

## 🧩 Installation

```bash
composer require emeroid/laravel-billing-core
```

### 1. Configuration

#### Publish the Config File

```bash
php artisan vendor:publish --provider="Emeroid\Billing\BillingServiceProvider" --tag="billing-config"
```

> This creates `config/billing.php`.

#### Publish the Migrations

```bash
php artisan vendor:publish --provider="Emeroid\Billing\BillingServiceProvider" --tag="billing-migrations"
```

> This adds the **plans**, **subscriptions**, and **transactions** tables.

#### Run the Migrations

```bash
php artisan migrate
```

#### Update Your `.env` File

```dotenv
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
```

#### Add the `Billable` Trait

```php
// app/Models/User.php

use Emeroid\Billing\Traits\Billable;
use Illuminate{...};

class User extends Authenticatable
{
    use Billable, HasFactory, Notifiable;
    // ...
}
```

---

## 💳 Usage

### 2. Creating Plans

Before creating subscriptions, define your plans in the `plans` table — typically via a seeder:

```php
// database/seeders/PlanSeeder.php

use Emeroid\Billing\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'amount' => 500000, // 5000 NGN (in kobo)
            'interval' => 'monthly',
            'paystack_plan_id' => 'PL_abc123',
            'paypal_plan_id' => 'P-xyz456',
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
```

---

### 3. One-Time Payments

```php
use Emeroid\Billing\Facades\Billing;
use Illuminate\Http\Request;

class PaymentController
{
    public function startPayment(Request $request)
    {
        $user = $request->user();
        $amountInKobo = 50000; // 5000 NGN

        try {
            $payment = Billing::purchase($amountInKobo, $user->email, [
                'user_id' => $user->id,
                'currency' => 'NGN',
            ]);

            return redirect()->away($payment['authorization_url']);

        } catch (\Emeroid\Billing\Exceptions\PaymentInitializationFailedException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
```

---

### 4. Subscriptions

```php
use Emeroid\Billing\Facades\Billing;
use Emeroid\Billing\Models\Plan;
use Illuminate\Http\Request;

class SubscriptionController
{
    public function startSubscription(Request $request)
    {
        $user = $request->user();
        $plan = Plan::where('slug', 'pro-plan')->firstOrFail();

        $gatewayPlanId = config('billing.default') === 'paypal'
            ? $plan->paypal_plan_id
            : $plan->paystack_plan_id;

        try {
            $subscription = Billing::subscribe(
                $gatewayPlanId,
                $user->email,
                [
                    'amount' => $plan->amount,
                    'user_id' => $user->id,
                    'currency' => 'NGN',
                ]
            );

            return redirect()->away($subscription['authorization_url']);

        } catch (\Emeroid\Billing\Exceptions\PaymentInitializationFailedException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
```

---

### 5. Handling Callbacks

After payment, users are redirected to your site.
The built-in `CallbackController` handles:

* Verifying the transaction via `Billing::verifyTransaction(...)`
* Creating the Subscription record
* Firing events (`TransactionSuccessful`, `SubscriptionStarted`)
* Redirecting to success/failure URLs (defined in `config/billing.php`)

All of this happens **automatically**.

---

### 6. Handling Webhooks

Add these URLs to your gateway dashboards:

```
https://your-app.com/billing-webhooks/paystack
https://your-app.com/billing-webhooks/paypal
```

The package automatically handles:

* `charge.success` → verifies payments
* `subscription.create` → creates subscriptions
* `subscription.disable` → triggers `SubscriptionCancelled`
* Dunning events:

  * `invoice.payment_failed` (Paystack)
  * `BILLING.SUBSCRIPTION.PAYMENT.FAILED` (PayPal)

---

### 7. The `Billable` Trait API

```php
$user = auth()->user();

// STATUS CHECKS
$user->isSubscribed();
$user->onGracePeriod();
$user->hasActiveSubscription();
$user->isSubscribedTo('pro-plan');
$user->isPastDue();

// MANAGEMENT
$subscription = $user->getSubscription('SUB_abc');

$user->cancelSubscription($subscription->gateway_subscription_id);
$user->swapPlan($subscription->gateway_subscription_id, 'business-plan');
$user->syncSubscription($subscription->gateway_subscription_id);
```

---

### 8. Events

Listen for billing events in your `EventServiceProvider`:

```php
// app/Providers/EventServiceProvider.php

use Emeroid\Billing\Events\{
    TransactionSuccessful,
    SubscriptionStarted,
    SubscriptionCancelled,
    SubscriptionPlanSwapped,
    SubscriptionPaymentFailed
};

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
```

---

## 🧪 Testing

```bash
composer test
```

---

## 🤝 Contributing

Please see **CONTRIBUTING.md** for details.

---

## 🔒 Security

If you discover any security-related issues, please email
📧 **[threalyongbug@gmail.com](mailto:threalyongbug@gmail.com)** instead of using the issue tracker.

---

## 📄 License

Licensed under the **MIT License (MIT)**.
See the [License File](LICENSE) for more information.

---

## ❤️ Sponsorship

This project is free and open-source.
If it helps you build your business, please consider supporting its development.

**Sponsor [@emeroid](https://github.com/emeroid)** on GitHub.

---

Would you like me to add a **table of contents** and automatic code syntax highlighting for GitHub (for example, by tagging PHP blocks as ` ```php`)?
