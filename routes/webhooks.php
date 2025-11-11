<?php

use Illuminate\Support\Facades\Route;
use Emeroid\Billing\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Billing Webhook Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the BillingServiceProvider.
| The prefix (e.g., 'billing-webhooks') is set in your
| config/billing.php file.
|
*/

Route::post('paystack', [WebhookController::class, 'handlePaystack'])->name('billing.webhook.paystack');
Route::post('paypal', [WebhookController::class, 'handlePaypal'])->name('billing.webhook.paypal');