<?php

namespace Emeroid\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Emeroid\Billing\Facades\Billing;
use Emeroid\Billing\Exceptions\TransactionVerificationFailedException;

/**
 * Handles the user-facing redirect from the payment gateway.
 */
class CallbackController extends Controller
{
    public function handleCallback(Request $request, string $gateway)
    {
        $driver = Billing::driver($gateway);

        // Get the reference key (this differs by gateway)
        $reference = match($gateway) {
            'paystack' => $request->query('reference'),
            'paypal' => $request->query('token'), // PayPal Order ID
            default => null,
        };

        if (!$reference) {
            return $this->redirect('failure', 'Invalid callback parameters.');
        }

        try {
            // This method handles all logic:
            // 1. Verifies the transaction
            // 2. Updates the DB
            // 3. Fires events
            // 4. Creates a subscription if needed
            $transaction = $driver->verifyTransaction($reference);
            
            // Note: For PayPal subscriptions, the subscription ID is in the query
            // You might need additional logic here to sync the subscription
            // if ($gateway === 'paypal' && $request->query('subscription_id')) {
            //    Billing::driver('paypal')->getSubscriptionDetails($request->query('subscription_id'));
            // }

            return $this->redirect('success', 'Payment successful!');

        } catch (TransactionVerificationFailedException $e) {
            report($e);
            return $this->redirect('failure', $e->getMessage());
        } catch (\Exception $e) {
            // Catch-all for other errors (e.g., model not found)
            report($e);
            return $this->redirect('failure', 'An unexpected error occurred.');
        }
    }

    /**
     * Redirect the user to the configured success or failure URL.
     */
    protected function redirect(string $status, string $message)
    {
        $url = config("billing.redirects.{$status}", '/');
        $key = ($status === 'success') ? 'billing_message' : 'billing_error';
        
        return redirect($url)->with($key, $message);
    }
}