<?php

namespace Emeroid\Billing\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Emeroid\Billing\Events\SubscriptionCancelled;
use Emeroid\Billing\Events\SubscriptionStarted;
use Emeroid\Billing\Events\TransactionSuccessful;
use Emeroid\Billing\Events\SubscriptionPlanSwapped;
use Emeroid\Billing\Events\SubscriptionPaymentFailed;
use Emeroid\Billing\Facades\Billing;
use Emeroid\Billing\Models\Plan;
use Emeroid\Billing\Models\Subscription;
use Emeroid\Billing\Models\Transaction;
use Emeroid\Billing\Tests\TestCase;

class PaystackSubscriptionTest extends TestCase
{
    protected $plan;
    protected $businessPlan;

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
        
        // Create a second plan for swapping
        $this->businessPlan = Plan::create([ // <-- FIXED: Was this.businessPlan
            'name' => 'Business Plan',
            'slug' => 'business-plan',
            'amount' => 100000,
            'interval' => 'monthly',
            'paystack_plan_id' => 'PL_biz_456',
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
                'test_user_id' => $this->user->id,
                'reference' => 'trx_sub_123'
                
            ]
        );

        $this->assertEquals('https://checkout.paystack.com/sub_123', $response['authorization_url']);
        
        // Assert a pending transaction was created for the *initial charge*
        $this->assertDatabaseHas('transactions', [
            'test_user_id' => $this->user->id,
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
            'test_user_id' => $this->user->id,
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
                    'authorization' => ['authorization_code' => 'AUTH_abc123'], // V2.0 data
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
            'test_user_id' => $this->user->id, // <-- FIXED
            'plan_id' => $this->plan->id,
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_abc',
            'status' => 'active',
            'authorization_code' => 'AUTH_abc123', // V2.0 data
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
            'test_user_id' => $this->user->id, // <-- FIXED
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

    /** @test */
    public function it_can_swap_a_subscription_plan()
    {
        // 1. Create an active subscription with an auth code
        $subscription = Subscription::create([
            'test_user_id' => $this->user->id, // <-- FIXED
            'plan_id' => $this->plan->id,
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_swap_123',
            'status' => 'active',
            'authorization_code' => 'AUTH_swap_123',
        ]);

        // 2. Fake the Paystack API calls
        Http::fake([
            // The PUT call to swap the plan
            'api.paystack.co/subscription/SUB_swap_123' => Http::response([
                'status' => true,
                'message' => 'Subscription updated',
            ], 200),
            // The GET call from getSubscriptionDetails (which is called after)
            'api.paystack.co/subscription/SUB_swap_123' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'active',
                    'plan' => 'PL_biz_456', // The new plan
                    'next_payment_date' => now()->addMonth()->toIso8601String(),
                ],
            ]),
        ]);

        // 3. Call the swapPlan method
        $this->user->swapPlan('SUB_swap_123', 'business-plan');

        // 4. Assertions
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'plan_id' => $this->businessPlan->id, // It's on the new plan
            'status' => 'active',
        ]);
        
        $this->assertTrue($this->user->isSubscribedTo('business-plan')); // <-- FIXED: Was $this.assertTrue
        $this->assertFalse($this->user->isSubscribedTo('pro-plan'));
        Event::assertDispatched(SubscriptionPlanSwapped::class);
    }

    /** @test */
    public function it_handles_dunning_on_a_failed_payment_webhook()
    {
        // 1. Create an active subscription
        $subscription = Subscription::create([
            'test_user_id' => $this->user->id, // <-- FIXED
            'plan_id' => $this->plan->id,
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_dunning_123',
            'status' => 'active',
        ]);

        // 2. Create the fake webhook body
        $body = [
            'event' => 'invoice.payment_failed',
            'data' => [
                'subscription' => [
                    'subscription_code' => 'SUB_dunning_123',
                ],
                // ...other data
            ]
        ];
        
        // 3. Sign the webhook
        $secret = config('billing.drivers.paystack.secret_key');
        $signature = hash_hmac('sha512', json_encode($body), $secret);

        // 4. Send the webhook
        $response = $this->postJson('/billing-webhooks/paystack', 
            $body, 
            ['x-paystack-signature' => $signature]
        );
        
        // 5. Assertions
        $response->assertStatus(200);

        $this->assertDatabaseHas('subscriptions', [ // <-- FIXED: Was $this.assertDatabaseHas
            'id' => $subscription->id,
            'status' => 'past_due', // It's now past_due
        ]);
        
        $this->assertTrue($this->user->isPastDue()); // <-- FIXED: Was $this.assertTrue
        Event::assertDispatched(SubscriptionPaymentFailed::class);
    }
}