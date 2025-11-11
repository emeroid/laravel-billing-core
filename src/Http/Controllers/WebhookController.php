<?php

namespace Emeroid\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Emeroid\Billing\Facades\Billing; // We will create this facade

/**
 * This controller receives all webhooks and routes
 * them to the correct driver.
 */
class WebhookController extends Controller
{
    public function handlePaystack(Request $request)
    {
        // Delegate the work to the Paystack driver
        return Billing::driver('paystack')->handleWebhook($request);
    }

    public function handlePaypal(Request $request)
    {
        // Delegate the work to the PayPal driver
        return Billing::driver('paypal')->handleWebhook($request);
    }
}