<?php

namespace Emeroid\Billing\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Emeroid\Billing\Contracts\GatewayContract;
use Emeroid\Billing\Drivers\PayPalClient;
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

/**
 * PayPal API Driver.
 * This driver uses raw HTTP calls to the PayPal v1 and v2 APIs.
 * v2/checkout/orders for one-time payments.
 * v1/billing/subscriptions for recurring subscriptions.
 */
class PaypalDriver extends AbstractDriver implements GatewayContract
{
    protected $client;

    public function __construct(array $config)
    {
        parent::__construct($config);
        // Create our new, Guzzle-based client
        $this->client = new PayPalClient($config);
    }

    public function driver(?string $driver = null): GatewayContract
    {
        throw new \BadMethodCallException('Cannot call driver() on a specific driver instance.');
    }

    /**
     * Initiate a one-time purchase using the v2/checkout/orders API.
     */
    public function purchase(int $amount, string $email, array $options = []): array
    {
        $currency = $options['currency'] ?? 'USD';
        $value = number_format($amount / 100, 2, '.', '');
        $reference = 'trx_' . uniqid();

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $reference,
                'amount' => [
                    'currency_code' => $currency,
                    'value' => $value,
                ],
            ]],
            'application_context' => [
                'return_url' => $options['return_url'] ?? route('billing.callback.gateway', ['gateway' => 'paypal']),
                'cancel_url' => $options['cancel_url'] ?? url('/'),
                'brand_name' => config('app.name'),
                'user_action' => 'PAY_NOW',
            ],
        ];

        try {
            $response = $this->client->post('/v2/checkout/orders', $payload);
            $order = $response->json();

            $approveLink = collect($order['links'])->firstWhere('rel', 'approve');
            if (!$approveLink) {
                throw new \Exception('No approve link found in PayPal response.');
            }

            $billableModel = app(config('billing.model'));
            $billableForeignKey = $billableModel->getForeignKey();

            $this->createPendingTransaction(
                $order['id'], // Use PayPal Order ID as reference
                $amount,
                $email,
                'paypal',
                $options[$billableForeignKey] ?? null,
                $currency
            );

            return [
                'authorization_url' => $approveLink['href'],
                'reference' => $order['id'],
            ];
        } catch (\Exception $e) {
            throw new PaymentInitializationFailedException('PayPal: '." (purchase) " . $e->getMessage());
        }
    }

    /**
     * Initiate a subscription using the v1/billing/subscriptions API.
     */
    public function subscribe(string $planId, string $email, array $options = []): array
    {
        $payload = [
            'plan_id' => $planId,
            'subscriber' => [
                'email_address' => $email,
            ],
            'application_context' => [
                'return_url' => $options['return_url'] ?? route('billing.callback.gateway', ['gateway' => 'paypal', 'sub' => true]),
                'cancel_url' => $options['cancel_url'] ?? url('/'),
                'brand_name' => config('app.name'),
                'user_action' => 'SUBSCRIBE_NOW',
            ],
        ];

        try {
            $response = $this->client->post('/v1/billing/subscriptions', $payload);
            $subscription = $response->json();

            $approveLink = collect($subscription['links'])->firstWhere('rel', 'approve');
            if (!$approveLink) {
                throw new \Exception('No approve link found in PayPal response.');
            }

            return [
                'authorization_url' => $approveLink['href'],
                'reference' => $subscription['id'],
            ];
        } catch (\Exception $e) {
            throw new PaymentInitializationFailedException('PayPal: '." (subscribe) " . $e->getMessage());
        }
    }

    /**
     * Verify a one-time payment using the v2/checkout/orders API.
     */
    public function verifyTransaction(string $reference): Transaction
    {
        $transaction = Transaction::where('reference', $reference)->where('gateway', 'paypal')->firstOrFail();

        if ($transaction->status === 'success') {
            return $transaction; // Idempotency
        }

        try {
            // 1. Get Order details to check status
            $response = $this->client->get("/v2/checkout/orders/{$reference}");
            $order = $response->json();
            
            if ($order['status'] === 'COMPLETED') {
                return $this->processSuccessfulOrder($order, $transaction);
            }
            
            // 2. If not complete, attempt to capture
            if ($order['status'] === 'APPROVED') {
                $captureResponse = $this->client->post("/v2/checkout/orders/{$reference}/capture", []);
                $capturedOrder = $captureResponse->json();
                return $this->processSuccessfulOrder($capturedOrder, $transaction);
            }

            throw new \Exception("Order status is {$order['status']}, cannot capture.");

        } catch (\Exception $e) {
            $transaction->status = 'failed';
            $transaction->gateway_response = ['error' => $e->getMessage()];
            $transaction->save();
            event(new TransactionFailed($transaction));
            throw new TransactionVerificationFailedException('PayPal: '." (verify) " . $e->getMessage());
        }
    }
    
    protected function processSuccessfulOrder(array $order, Transaction $transaction): Transaction
    {
        $transaction->status = 'success';
        $transaction->gateway_response = $order;
        $transaction->paid_at = now();
        $transaction->save();

        event(new TransactionSuccessful($transaction));
        return $transaction;
    }

    /**
     * Handle incoming webhooks for all event types.
     */
    public function handleWebhook(Request $request)
    {
        try {
            $this->verifyWebhookSignature($request);
        } catch (WebhookVerificationFailedException $e) {
            report($e);
            return response()->json(['error' => $e->getMessage()], 401);
        }

        $event = $request->input('event_type');
        $resource = $request->input('resource');

        switch ($event) {
            case 'CHECKOUT.ORDER.APPROVED':
            case 'CHECKOUT.ORDER.COMPLETED':
            case 'PAYMENT.CAPTURE.COMPLETED':
                $orderId = $resource['id'];
                $transaction = Transaction::where('reference', $orderId)->first();
                if ($transaction && $transaction->status !== 'success') {
                    $this->verifyTransaction($orderId);
                }
                break;

            case 'BILLING.SUBSCRIPTION.ACTIVATED':
                $subscriptionId = $resource['id'];
                $planId = $resource['plan_id'];
                $email = $resource['subscriber']['email_address'];
                
                $plan = Plan::where('paypal_plan_id', $planId)->first();
                $user = $this->findBillableByEmail($email);
                
                $billableModel = app(config('billing.model'));
                $billableForeignKey = $billableModel->getForeignKey();

                if ($plan && $user) {
                    $subscription = Subscription::firstOrCreate([
                        'gateway_subscription_id' => $subscriptionId,
                        'gateway' => 'paypal',
                    ], [
                        $billableForeignKey => $user->id,
                        'plan_id' => $plan->id,
                        'status' => 'active',
                        'customer_code' => $resource['subscriber']['payer_id'] ?? null,
                        'authorization_code' => $subscriptionId, // For PayPal, sub ID is the auth
                        'ends_at' => null,
                    ]);
                    
                    if ($subscription->wasRecentlyCreated) {
                        event(new SubscriptionStarted($subscription));
                    }
                }
                break;
            
            case 'BILLING.SUBSCRIPTION.CANCELLED':
                $subscriptionId = $resource['id'];
                $subscription = Subscription::where('gateway_subscription_id', $subscriptionId)->first();
                if ($subscription) {
                    $subscription->status = 'cancelled';
                    $this->getSubscriptionDetails($subscription); // Sync to get end date
                    event(new SubscriptionCancelled($subscription));
                }
                break;

            case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
                $subscriptionId = $resource['id'];
                $subscription = Subscription::where('gateway_subscription_id', $subscriptionId)->first();
                if ($subscription) {
                    $subscription->status = 'past_due';
                    $subscription->save();
                    event(new SubscriptionPaymentFailed($subscription));
                }
                break;
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Cancel a subscription using the v1/billing/subscriptions API.
     */
    public function cancelSubscription(Subscription $subscription, string $reason = 'Cancelled by user'): Subscription
    {
        $subDetails = $this->getSubscriptionDetails($subscription);
        $endsAt = $subDetails->ends_at;

        try {
            $this->client->post("/v1/billing/subscriptions/{$subscription->gateway_subscription_id}/cancel", [
                'reason' => $reason
            ]);
            
            $subscription->status = 'cancelled';
            $subscription->ends_at = $endsAt;
            $subscription->save();

            event(new SubscriptionCancelled($subscription));
            return $subscription;
        } catch (\Exception $e) {
            throw new \Exception('PayPal: Failed to cancel subscription. ' . $e->getMessage());
        }
    }
    
    /**
     * Get subscription details from the v1/billing/subscriptions API.
     */
    public function getSubscriptionDetails(Subscription $subscription): Subscription
    {
        try {
            $response = $this->client->get("/v1/billing/subscriptions/{$subscription->gateway_subscription_id}");
            $subData = $response->json();
            
            $subscription->status = strtolower($subData['status']);
            
            if ($subData['status'] === 'ACTIVE' && isset($subData['billing_info']['next_billing_time'])) {
                $subscription->ends_at = now()->parse($subData['billing_info']['next_billing_time']);
            }

            $subscription->save();
            return $subscription;
        } catch (\Exception $e) {
            throw new \Exception('PayPal: Failed to get subscription details. ' . $e->getMessage());
        }
    }

    /**
     * Swap a subscription plan using the v1/billing/subscriptions API.
     */
    public function swapPlan(Subscription $subscription, Plan $newPlan): Subscription
    {
        $newPlanGatewayId = $newPlan->paypal_plan_id;

        try {
            // PayPal's "swap" is a "revise" call.
            $this->client->post("/v1/billing/subscriptions/{$subscription->gateway_subscription_id}/revise", [
                'plan_id' => $newPlanGatewayId
            ]);

            $subscription->plan_id = $newPlan->id;
            $subscription->save();
            
            return $this->getSubscriptionDetails($subscription);

        } catch (\Exception $e) {
            throw new \Exception('PayPal: Failed to swap plan. ' . $e->getMessage());
        }
    }

    /**
     * Verify a webhook signature using the v1/notifications API.
     */
    protected function verifyWebhookSignature(Request $request): void
    {
        $payload = [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => $this->config['webhook_id'],
            'webhook_event' => $request->all(),
        ];

        try {
            $response = $this->client->post('/v1/notifications/verify-webhook-signature', $payload);
            $verification = $response->json();
            
            if ($verification['verification_status'] !== 'SUCCESS') {
                throw new \Exception('Webhook verification status not SUCCESS.');
            }
        } catch (\Exception $e) {
            throw new WebhookVerificationFailedException('PayPal: ' . $e->getMessage());
        }
    }
}