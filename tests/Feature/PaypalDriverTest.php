<?php

namespace Emeroid\Billing\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Emeroid\Billing\Events\TransactionSuccessful;
use Emeroid\Billing\Facades\Billing;
use Emeroid\Billing\Models\Transaction;
use Emeroid\Billing\Tests\TestCase;

/**
 * NOTE: Testing PayPal is more complex due to the SDK.
 * This test fakes the HTTP client Guzzle *inside* the SDK.
 * A more robust test would mock the PayPalClient itself.
 */
class PaypalDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    /** @test */
    public function it_can_initialize_a_paypal_purchase()
    {
        // This is a simplified mock. The PayPal SDK makes multiple calls (auth, then create)
        // For a real test, you'd mock the PayPalClient service
        Http::fake([
            'api.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            'api.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAYPAL_ORDER_ID_123',
                'status' => 'CREATED',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://sandbox.paypal.com/checkout/123'],
                ],
            ], 201),
        ]);

        $response = Billing::driver('paypal')->purchase(5000, $this->user->email, [
            'currency' => 'USD',
            'user_id' => $this->user->id,
            'return_url' => 'https://my.app/return',
        ]);
        
        $this->assertEquals('https://sandbox.paypal.com/checkout/123', $response['authorization_url']);
        $this->assertEquals('PAYPAL_ORDER_ID_123', $response['reference']);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->user->id,
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
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'reference' => 'PAYPAL_ORDER_ID_456',
            'gateway' => 'paypal',
            'amount' => 5000,
            'status' => 'pending',
        ]);

        Http::fake([
            'api.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
            // 1. GetRequest
            'api.sandbox.paypal.com/v2/checkout/orders/PAYPAL_ORDER_ID_456' => Http::response([
                'id' => 'PAYPAL_ORDER_ID_456',
                'status' => 'APPROVED',
            ]),
            // 2. CaptureRequest
            'api.sandbox.paypal.com/v2/checkout/orders/PAYPAL_ORDER_ID_456/capture' => Http::response([
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
}