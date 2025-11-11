<?php

namespace Emeroid\Billing\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Emeroid\Billing\Events\TransactionSuccessful;
use Emeroid\Billing\Events\TransactionFailed;
use Emeroid\Billing\Facades\Billing;
use Emeroid\Billing\Models\Transaction;
use Emeroid\Billing\Tests\TestCase;

class PaystackDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake(); // Fake events to allow assertions
    }

    /** @test */
    public function it_can_initialize_a_purchase()
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/trx_123',
                    'reference' => 'trx_12345',
                ],
            ], 200),
        ]);

        $response = Billing::driver('paystack')->purchase(50000, $this->user->email, [
            'currency' => 'NGN',
            'user_id' => $this->user->id,
            'reference' => 'trx_12345'
        ]);

        $this->assertEquals('https://checkout.paystack.com/trx_123', $response['authorization_url']);
        $this->assertEquals('trx_12345', $response['reference']);

        // Assert a pending transaction was created
        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->user->id,
            'reference' => 'trx_12345',
            'gateway' => 'paystack',
            'amount' => 50000,
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function it_can_verify_a_successful_transaction()
    {
        // Create a pending transaction first
        $pendingTx = Transaction::create([
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'reference' => 'trx_abc',
            'gateway' => 'paystack',
            'amount' => 50000,
            'status' => 'pending',
        ]);

        Http::fake([
            'api.paystack.co/transaction/verify/trx_abc' => Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => [
                    'status' => 'success',
                    'reference' => 'trx_abc',
                    'amount' => 50000,
                    'plan' => null, // Not a subscription
                ],
            ], 200),
        ]);

        $transaction = Billing::driver('paystack')->verifyTransaction('trx_abc');

        $this->assertEquals('success', $transaction->status);
        $this->assertNotNull($transaction->paid_at);
        $this->assertDatabaseHas('transactions', [
            'reference' => 'trx_abc',
            'status' => 'success',
        ]);
        
        // Assert the event was fired
        Event::assertDispatched(TransactionSuccessful::class, function ($event) use ($transaction) {
            return $event->transaction->id === $transaction->id;
        });
    }

    /** @test */
    public function it_can_handle_a_failed_transaction_verification()
    {
        $pendingTx = Transaction::create([
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'reference' => 'trx_failed',
            'gateway' => 'paystack',
            'amount' => 50000,
            'status' => 'pending',
        ]);

        Http::fake([
            'api.paystack.co/transaction/verify/trx_failed' => Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => [
                    'status' => 'failed',
                    'reference' => 'trx_failed',
                    'amount' => 50000,
                ],
            ], 200),
        ]);

        $transaction = Billing::driver('paystack')->verifyTransaction('trx_failed');

        $this->assertEquals('failed', $transaction->status);
        $this->assertDatabaseHas('transactions', [
            'reference' => 'trx_failed',
            'status' => 'failed',
        ]);
        
        Event::assertDispatched(TransactionFailed::class);
        Event::assertNotDispatched(TransactionSuccessful::class);
    }
    
    /** @test */
    public function it_handles_a_webhook_signature_verification()
    {
        $secret = config('billing.drivers.paystack.secret_key');
        $body = '{"event":"charge.success","data":{...}}';
        $signature = hash_hmac('sha512', $body, $secret);

        $response = $this->postJson('/billing-webhooks/paystack', 
            json_decode($body, true), 
            ['x-paystack-signature' => $signature]
        );

        $response->assertStatus(200);
    }

    /** @test */
    public function it_rejects_an_invalid_webhook_signature()
    {
        $response = $this->postJson('/billing-webhooks/paystack', 
            ['event' => 'charge.success'], 
            ['x-paystack-signature' => 'invalid-signature']
        );

        $response->assertStatus(401);
    }
}