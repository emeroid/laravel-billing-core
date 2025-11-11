<?php

namespace Emeroid\Billing\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Emeroid\Billing\Events\SubscriptionCancelled;
use Emeroid\Billing\Events\SubscriptionStarted;
use Emeroid\Billing\Events\TransactionSuccessful;
use Emeroid\Billing\Facades\Billing;
use Emeroid\Billing\Models\Plan;
use Emeroid\Billing\Models\Subscription;
use Emeroid\Billing\Models\Transaction;
use Emeroid\Billing\Tests\TestCase;

class PaystackSubscriptionTest extends TestCase
{
    protected $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        // Create a test plan
        $this->plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'amount' => 50000,
            'interval' => 'monthly',
            'paystack_plan_id' => 'PL_pro_123',
        ]);
    }

    /** @test */
    public function it_can_initialize_a_subscription()
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/sub_123',
                    'reference' => 'trx_sub_123',
                ],
            ], 200),
        ]);

        $response = Billing::driver('paystack')->subscribe(
            $this->plan->paystack_plan_id,
            $this->user->email,
            [
                'amount' => $this->plan->amount,
                'currency' => 'NGN',
                'user_id' => $this->user->id,
            ]
        );

        $this->assertEquals('https://checkout.paystack.com/sub_123', $response['authorization_url']);
        
        // Assert a pending transaction was created for the *initial charge*
        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->user->id,
            'reference' => 'trx_sub_123',
            'gateway' => 'paystack',
            'amount' => 50000,
            'status' => 'pending',
            'gateway_plan_id' => 'PL_pro_123',
        ]);
    }

    /** @test */
    public function it_creates_a_subscription_when_verifying_a_subscription_transaction()
    {
        // 1. Create the pending transaction, as if user was redirected to Paystack
        $pendingTx = Transaction::create([
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'reference' => 'trx_sub_456',
            'gateway' => 'paystack',
            'amount' => 50000,
            'status' => 'pending',
            'gateway_plan_id' => $this->plan->paystack_plan_id,
        ]);

        // 2. Fake the verification response from Paystack
        Http::fake([
            'api.paystack.co/transaction/verify/trx_sub_456' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => 'trx_sub_456',
                    'amount' => 50000,
                    'plan' => $this->plan->paystack_plan_id, // Paystack confirms it's a plan
                    'subscription_code' => 'SUB_abc', // The new subscription ID
                    'customer' => ['email' => $this->user->email],
                ],
            ], 200),
        ]);

        // 3. Call verifyTransaction (e.g., from the callback controller)
        $transaction = Billing::driver('paystack')->verifyTransaction('trx_sub_456');

        // 4. Assertions
        $this->assertEquals('success', $transaction->status);
        Event::assertDispatched(TransactionSuccessful::class);

        // Assert the subscription was created in our DB
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_abc',
            'status' => 'active',
        ]);
        
        // Assert the subscription event was fired
        Event::assertDispatched(SubscriptionStarted::class, function ($event) {
            return $event->subscription->gateway_subscription_id === 'SUB_abc';
        });
    }

    /** @test */
    public function it_can_cancel_a_subscription_with_a_grace_period()
    {
        // 1. Create an active subscription
        $subscription = Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_xyz',
            'status' => 'active',
        ]);

        $nextPaymentDate = now()->addDays(20);

        // 2. Fake the Paystack API calls
        Http::fake([
            // The first call (from getSubscriptionDetails)
            'api.paystack.co/subscription/SUB_xyz' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'active',
                    'next_payment_date' => $nextPaymentDate->toIso8601String(),
                ],
            ]),
            // The second call (to cancel)
            'api.paystack.co/subscription/disable' => Http::response([
                'status' => true,
                'message' => 'Subscription disabled',
            ], 200),
        ]);

        // 3. Call the method on the User model
        $this->user->cancelSubscription('SUB_xyz');

        // 4. Assertions
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'cancelled',
            'ends_at' => $nextPaymentDate->toDateTimeString(), // Crucial: It's set to the future
        ]);
        
        $this->assertTrue($this->user->onGracePeriod());
        $this->assertFalse($this->user->isSubscribed());
        $this->assertTrue($this->user->hasActiveSubscription());

        Event::assertDispatched(SubscriptionCancelled::class);
    }
}