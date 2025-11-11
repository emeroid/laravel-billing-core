<?php

namespace Emeroid\Billing\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Emeroid\Billing\Contracts\GatewayContract;
use Emeroid\Billing\Events\SubscriptionCancelled;
use Emeroid\Billing\Events\SubscriptionPaymentFailed;
use Emeroid\Billing\Events\SubscriptionStarted;
use Emeroid\Billing\Events\TransactionFailed;
use Emeroid\Billing\Events\TransactionSuccessful;
use Emeroid\Billing\Exceptions\PaymentInitializationFailedException;
use Emeroid\Billing\Exceptions\TransactionVerificationFailedException;
use Emeroid\Billing\Exceptions\WebhookVerificationFailedException;
use Emeroid\Billing\Models\Plan;
use Emeroid\Billing\Models\Subscription;
use Emeroid\Billing\Models\Transaction;

class PaystackDriver extends AbstractDriver implements GatewayContract
{
    protected $http;
    protected $baseUrl = 'https://api.paystack.co';

    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->http = Http::withToken($this->config['secret_key'])
            ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
            ->baseUrl($this->baseUrl);
    }

    public function driver(?string $driver = null): GatewayContract
    {
        throw new \BadMethodCallException('Cannot call driver() on a specific driver instance.');
    }

    public function purchase(int $amount, string $email, array $options = []): array
    {
        $reference = $options['reference'] ?? 'trx_' . uniqid();

        $response = $this->http->post('/transaction/initialize', array_merge($options, [
            'amount' => $amount,
            'email' => $email,
            'reference' => $reference,
            'currency' => $options['currency'] ?? 'NGN',
        ]));

        $data = $response->json();

        if (!$response->successful() || $data['status'] !== true) {
            throw new PaymentInitializationFailedException('Paystack: ' . ($data['message'] ?? 'Unknown error'));
        }

        $this->createPendingTransaction(
            $reference,
            $amount,
            $email,
            'paystack',
            $options[$this->billableForeignKey()] ?? null,
            $options['currency'] ?? 'NGN'
        );

        return [
            'authorization_url' => $data['data']['authorization_url'],
            'reference' => $data['data']['reference'],
        ];
    }

    public function subscribe(string $planId, string $email, array $options = []): array
    {
        $reference = $options['reference'] ?? 'sub_' . uniqid();

        $response = $this->http->post('/transaction/initialize', array_merge($options, [
            'email' => $email,
            'amount' => $options['amount'], // Amount for the first charge
            'plan' => $planId,
            'reference' => $reference,
            'currency' => $options['currency'] ?? 'NGN',
        ]));

        $data = $response->json();

        if (!$response->successful() || $data['status'] !== true) {
            throw new PaymentInitializationFailedException('Paystack (Subscription): ' . ($data['message'] ?? 'Unknown error'));
        }

        $billableModel = app(config('billing.model'));
        $billableForeignKey = $billableModel->getForeignKey();

        $this->createPendingTransaction(
            $reference,
            $options['amount'],
            $email,
            'paystack',
            $options[$billableForeignKey] ?? null,
            $options['currency'] ?? 'NGN',
            $planId
        );

        return [
            'authorization_url' => $data['data']['authorization_url'],
            'reference' => $data['data']['reference'],
        ];
    }

    public function verifyTransaction(string $reference): Transaction
    {
        $transaction = Transaction::where('reference', $reference)->where('gateway', 'paystack')->firstOrFail();

        if ($transaction->status === 'success') {
            return $transaction; // Idempotency
        }

        $response = $this->http->get("/transaction/verify/{$reference}");
        $data = $response->json();

        if (!$response->successful() || $data['status'] !== true) {
            throw new TransactionVerificationFailedException('Paystack: ' . ($data['message'] ?? 'Verification failed'));
        }

        $txData = $data['data'];

        if ($txData['status'] === 'success') {
            $transaction->status = 'success';
            $transaction->gateway_response = $txData;
            $transaction->paid_at = now();
            
            if ($txData['plan'] && $transaction->{$this->billableForeignKey()}) {
                $subscription = $this->findOrCreateSubscription($txData, $transaction);
                event(new SubscriptionStarted($subscription));
            }

            event(new TransactionSuccessful($transaction));

        } else {
            $transaction->status = $txData['status']; // e.g., 'failed'
            $transaction->gateway_response = $txData;
            event(new TransactionFailed($transaction));
        }

        $transaction->save();
        return $transaction;
    }

    // public function handleWebhook(Request $request)
    // {
    //     $this->verifyWebhookSignature($request);

    //     $event = $request->input('event');
    //     $data = $request->input('data');

    //     try {
    //         switch ($event) {
    //             case 'charge.success':
    //                 $transaction = $this->verifyTransaction($data['reference']);
    //                 if ($data['plan'] && $transaction->{$this->billableForeignKey()}) {
    //                     $this->findOrCreateSubscription($data, $transaction);
    //                 }
    //                 break;
                
    //             case 'subscription.create':
    //                 $subscription = $this->findOrCreateSubscription($data, null);
    //                 event(new SubscriptionStarted($subscription));
    //                 break;

    //             case 'subscription.disable':
    //                 $subscription = Subscription::where('gateway_subscription_id', $data['subscription_code'])->first();
    //                 if ($subscription) {
    //                     $subscription->status = 'cancelled';
    //                     $subscription->save();
    //                     event(new SubscriptionCancelled($subscription));
    //                 }
    //                 break;
                
    //             case 'invoice.payment_failed':
    //                 $subscriptionCode = $data['subscription']['subscription_code'] ?? null;
    //                 if ($subscriptionCode) {
    //                     $subscription = Subscription::where('gateway_subscription_id', $subscriptionCode)->first();
    //                     if ($subscription) {
    //                         $subscription->status = 'past_due';
    //                         $subscription->save();
    //                         event(new SubscriptionPaymentFailed($subscription));
    //                     }
    //                 }
    //                 break;
    //         }
    //     } catch (\Exception $e) {
    //         report($e);
    //         return response()->json(['error' => 'Webhook handling failed.'], 500);
    //     }

    //     return response()->json(['status' => 'success']);
    // }

    public function handleWebhook(Request $request)
    {
        try {
            // Verify signature first — will throw WebhookVerificationFailedException on invalid/missing header
            $this->verifyWebhookSignature($request);

            $event = $request->input('event');
            $data = $request->input('data');

            switch ($event) {
                case 'charge.success':
                    // Find transaction first — don't call verifyTransaction() blindly because
                    // verifyTransaction() uses firstOrFail() and will throw if missing.
                    $reference = $data['reference'] ?? null;
                    if ($reference) {
                        $transaction = Transaction::where('reference', $reference)
                            ->where('gateway', 'paystack')
                            ->first();
                        if ($transaction) {
                            // Safe to call verifyTransaction which will update the transaction.
                            $transaction = $this->verifyTransaction($reference);

                            // If it's a plan/subscription and the transaction has a billable FK, create subscription
                            if (!empty($data['plan']) && !empty($transaction->{$this->billableForeignKey()})) {
                                $this->findOrCreateSubscription($data, $transaction);
                            }
                        }
                        // If transaction not found, acknowledge the webhook (return 200) — nothing to update locally.
                    }
                    break;

                case 'subscription.create':
                    $subscription = $this->findOrCreateSubscription($data, null);
                    event(new SubscriptionStarted($subscription));
                    break;

                case 'subscription.disable':
                    $subscription = Subscription::where('gateway_subscription_id', $data['subscription_code'])->first();
                    if ($subscription) {
                        $subscription->status = 'cancelled';
                        $subscription->save();
                        event(new SubscriptionCancelled($subscription));
                    }
                    break;

                case 'invoice.payment_failed':
                    $subscriptionCode = $data['subscription']['subscription_code'] ?? null;
                    if ($subscriptionCode) {
                        $subscription = Subscription::where('gateway_subscription_id', $subscriptionCode)->first();
                        if ($subscription) {
                            $subscription->status = 'past_due';
                            $subscription->save();
                            event(new SubscriptionPaymentFailed($subscription));
                        }
                    }
                    break;
            }

            return response()->json(['status' => 'success'], 200);

        } catch (\Emeroid\Billing\Exceptions\WebhookVerificationFailedException $e) {
            // Signature invalid -> 401 (tests expect this)
            return response()->json(['error' => $e->getMessage()], 401);

        } catch (\Exception $e) {
            // Unexpected error -> 500 (still report)
            report($e);
            return response()->json(['error' => 'Webhook handling failed.'], 500);
        }
    }


    public function cancelSubscription(Subscription $subscription, string $reason = 'Cancelled by user'): Subscription
    {
        $subDetails = $this->getSubscriptionDetails($subscription);
        $endsAt = $subDetails->ends_at;

        $response = $this->http->post('/subscription/disable', [
            'code' => $subscription->gateway_subscription_id,
            'token' => $subscription->authorization_code, // Need auth code!
        ]);

        $data = $response->json();

        if (!$response->successful() || $data['status'] !== true) {
            throw new \Exception('Paystack: Failed to cancel subscription. ' . ($data['message'] ?? ''));
        }

        $subscription->status = 'cancelled';
        $subscription->ends_at = $endsAt;
        $subscription->save();

        event(new SubscriptionCancelled($subscription));
        return $subscription;
    }
    
    public function getSubscriptionDetails(Subscription $subscription): Subscription
    {
        $response = $this->http->get("/subscription/{$subscription->gateway_subscription_id}");

        if (!$response->successful()) {
            throw new \Exception('Paystack: Failed to get subscription details.');
        }

        $subData = $response->json()['data'];

        $subscription->status = $subData['status']; // e.g., 'active', 'non-renewing'
        $subscription->ends_at = $subData['next_payment_date'] ? now()->parse($subData['next_payment_date']) : null;
        $subscription->save();

        return $subscription;
    }
    
    public function swapPlan(Subscription $subscription, Plan $newPlan): Subscription
    {
        if (empty($subscription->authorization_code)) {
            throw new \Exception('Cannot swap plan. Missing payment authorization code.');
        }

        $newPlanGatewayId = $newPlan->paystack_plan_id;

        $response = $this->http->put("/subscription/{$subscription->gateway_subscription_id}", [
            'plan' => $newPlanGatewayId,
            'authorization' => $subscription->authorization_code,
        ]);

        $data = $response->json();

        if (!$response->successful() || $data['status'] !== true) {
            throw new \Exception('Paystack: Failed to swap plan. ' . ($data['message'] ?? 'Unknown error'));
        }

        $subscription->plan_id = $newPlan->id;
        $subscription->save();

        return $this->getSubscriptionDetails($subscription);
    }

    protected function findOrCreateSubscription(array $data, ?Transaction $transaction): Subscription
    {
        $subscriptionCode = $data['subscription_code'] ?? ($data['subscription']['subscription_code'] ?? null);
        
        if (!$subscriptionCode) {
            throw new \Exception('No subscription code found in webhook data.');
        }

        $subscription = Subscription::firstOrNew([
            'gateway_subscription_id' => $subscriptionCode,
            'gateway' => 'paystack',
        ]);
    
        if (!$subscription->exists) {

            
            $user = null;
            if ($transaction) {
                $user = $this->getBillableFromTransaction($transaction);
            }
    
            if (!$user && isset($data['customer']['email'])) {
                $user = $this->findBillableByEmail($data['customer']['email']);
            }

            
            $planCode = $data['plan'] ?? ($data['plan']['plan_code'] ?? null);
            $plan = Plan::where('paystack_plan_id', $planCode)->first();

            if (!$user || !$plan) {
                throw new \Exception("Could not create subscription. Missing user or plan.");
            }
            
            $subscription->{$this->billableForeignKey()} = $user->id;
            $subscription->plan_id = $plan->id;
            $subscription->status = 'active';
            
            if (isset($data['customer'])) {
                $subscription->customer_code = $data['customer']['customer_code'] ?? null;
            }
            if (isset($data['authorization'])) {
                $subscription->authorization_code = $data['authorization']['authorization_code'] ?? null;
            }
            $subscription->save();
        }
    
        return $subscription;
    }

    protected function verifyWebhookSignature(Request $request): void
    {
        $signature = $request->header('x-paystack-signature');
        if (!$signature) {
            throw new WebhookVerificationFailedException('No signature header present.');
        }

        $hash = hash_hmac('sha512', $request->getContent(), $this->config['secret_key']);

        if (!hash_equals($hash, $signature)) {
            throw new WebhookVerificationFailedException('Invalid webhook signature.');
        }
    }
}