<?php

namespace Emeroid\Billing\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Emeroid\Billing\Events\TransactionSuccessful;
use Emeroid\Billing\Events\SubscriptionPlanSwapped;
use Emeroid\Billing\Events\SubscriptionPaymentFailed;
use Emeroid\Billing\Facades\Billing;
use Emeroid\Billing\Models\Plan;
use Emeroid\Billing\Models\Subscription;
use Emeroid\Billing\Models\Transaction;
use Emeroid\Billing\Tests\TestCase;

class PaypalDriverTest extends TestCase
{
    protected $plan;
    protected $businessPlan;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        
        $this->plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'amount' => 5000,
            'interval' => 'monthly',
            'paypal_plan_id' => 'P-PRO-123',
        ]);
        // Create a second plan for swapping
        $this->businessPlan = Plan::create([ // <-- FIXED: Was $this.businessPlan
            'name' => 'Business Plan',
            'slug' => 'business-plan',
            'amount' => 10000,
            'interval' => 'monthly',
            'paypal_plan_id' => 'P-BIZ-456',
        ]);
    }

    /** @test */
    public function it_can_initialize_a_paypal_purchase()
    {
        // This is a simplified mock. The PayPal SDK makes multiple calls (auth, then create)
        // For a real test, you'd mock the PayPalClient service
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]), // <-- FIXED: Added Auth
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAYPAL_ORDER_ID_123',
                'status' => 'CREATED',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://sandbox.paypal.com/checkout/123'],
                ],
            ], 201),
        ]);

        $response = Billing::driver('paypal')->purchase(5000, $this->user->email, [
            'currency' => 'USD',
            'test_user_id' => $this->user->id,
            'return_url' => 'https://my.app/return',
        ]);
        
        $this->assertEquals('https://sandbox.paypal.com/checkout/123', $response['authorization_url']);
        $this->assertEquals('PAYPAL_ORDER_ID_123', $response['reference']);

        $this->assertDatabaseHas('transactions', [
            'test_user_id' => $this->user->id,
            'reference' => 'PAYPAL_ORDER_ID_123',
            'gateway' => 'paypal',
            'amount' => 5000,
            'status' => 'pending',
        ]);
    }
    
    /** @test */
    public function it_can_verify_a_successful_paypal_transaction()
    {
        $pendingTx = Transaction::create([
            'test_user_id' => $this->user->id,
            'email' => $this->user->email,
            'reference' => 'PAYPAL_ORDER_ID_456',
            'gateway' => 'paypal',
            'amount' => 5000,
            'status' => 'pending',
        ]);

        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]), // <-- FIXED: Added Auth
            // 1. GetRequest
            'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL_ORDER_ID_456' => Http::response([
                'id' => 'PAYPAL_ORDER_ID_456',
                'status' => 'APPROVED',
            ]),
            // 2. CaptureRequest
            'api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL_ORDER_ID_456/capture' => Http::response([
                'id' => 'PAYPAL_ORDER_ID_456',
                'status' => 'COMPLETED',
                'purchase_units' => [],
            ], 201),
        ]);

        $transaction = Billing::driver('paypal')->verifyTransaction('PAYPAL_ORDER_ID_456');

        $this->assertEquals('success', $transaction->status);
        $this->assertDatabaseHas('transactions', [
            'reference' => 'PAYPAL_ORDER_ID_456',
            'status' => 'success',
        ]);
        
        Event::assertDispatched(TransactionSuccessful::class);
    }

    /** @test */
    public function it_can_swap_a_paypal_subscription_plan()
    {
        // 1. Create an active subscription
        $subscription = Subscription::create([
            'test_user_id' => $this->user->id, // <-- FIXED
            'plan_id' => $this->plan->id,
            'gateway' => 'paypal',
            'gateway_subscription_id' => 'I-SWAP123',
            'status' => 'active',
        ]);

        // 2. Fake the PayPal API calls
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]), // <-- FIXED: Added Auth
            // The POST call to revise/swap the plan
            'api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SWAP123/revise' => Http::response(null, 204), // 204 No Content is success
            // The GET call from getSubscriptionDetails (which is called after)
            'api-m.sandbox.paypal.com/v1/billing/subscriptions/I-SWAP123' => Http::response([
                'status' => 'ACTIVE',
                'plan_id' => 'P-BIZ-456', // The new plan
                'billing_info' => ['next_billing_time' => now()->addMonth()->toIso8601String()],
            ]),
        ]);

        // 3. Call the swapPlan method
        $this->user->swapPlan('I-SWAP123', 'business-plan');

        // 4. Assertions
        $this->assertDatabaseHas('subscriptions', [ // <-- FIXED: Was $this.assertDatabaseHas
            'id' => $subscription->id,
            'plan_id' => $this->businessPlan->id, // It's on the new plan
            'status' => 'active',
        ]);
        
        $this->assertTrue($this->user->isSubscribedTo('business-plan')); // <-- FIXED: Was $this.assertTrue
        Event::assertDispatched(SubscriptionPlanSwapped::class);
    }

    /** @test */
    public function it_handles_dunning_on_a_failed_payment_webhook()
    {
        // 1. Create an active subscription
        $subscription = Subscription::create([
            'test_user_id' => $this->user->id, // <-- FIXED
            'plan_id' => $this->plan->id,
            'gateway' => 'paypal',
            'gateway_subscription_id' => 'I-DUNNING123',
            'status' => 'active',
        ]);

        // 2. Create the fake webhook body
        $body = [
            'event_type' => 'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
            'resource' => [
                'id' => 'I-DUNNING123',
                // ...other data
            ]
        ];

        // 3. Fake the *webhook verification* call
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]), // <-- FIXED: Added Auth
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS'
            ]),
        ]);

        // 4. Send the webhook
        $response = $this->postJson('/billing-webhooks/paypal', $body);
        
        // 5. Assertions
        $response->assertStatus(200);

        $this->assertDatabaseHas('subscriptions', [ // <-- FIXED: Was $this.assertDatabaseHas
            'id' => $subscription->id,
            'status' => 'past_due',
        ]);
        
        $this->assertTrue($this->user->isPastDue()); // <-- FIXED: Was $this.assertTrue
        Event::assertDispatched(SubscriptionPaymentFailed::class);
    }
}