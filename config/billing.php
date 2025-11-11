<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Billing Gateway
    |--------------------------------------------------------------------------
    |
    | This option controls the default billing gateway that will be used
    | when no specific gateway is requested.
    |
    */
    'default' => env('BILLING_DEFAULT_DRIVER', 'paystack'),

    /*
    |--------------------------------------------------------------------------
    | Billing Gateway Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the billing drivers used by your
    | application. You are free to add your own drivers.
    |
    */
    'drivers' => [

        'paystack' => [
            'driver' => 'paystack', // This maps to the createPaystackDriver method
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        ],

        'paypal' => [
            'driver' => 'paypal', // This maps to the createPaypalDriver method
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'secret' => env('PAYPAL_SECRET'),
            'mode' => env('PAYPAL_MODE', 'sandbox'), // 'sandbox' or 'live'
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'), // CRITICAL: Add this for webhook verification
        ],

        // 'stripe' => [
        //     'driver' => 'stripe',
        //     'secret_key' => env('STRIPE_SECRET_KEY'),
        //     'public_key' => env('STRIPE_PUBLIC_KEY'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Routes
    |--------------------------------------------------------------------------
    |
    | Configuration for the webhook routes. These are server-to-server.
    |
    */
    'webhook_prefix' => env('BILLING_WEBHOOK_PREFIX', 'billing-webhooks'),
    'webhook_middleware' => env('BILLING_WEBHOOK_MIDDLEWARE', 'api'),
    
    /*
    |--------------------------------------------------------------------------
    | Callback Routes
    |--------------------------------------------------------------------------
    |
    | Configuration for user-facing callback routes. These are for
    | the browser redirect after payment.
    |
    */
    'callback_prefix' => env('BILLING_CALLBACK_PREFIX', 'billing-callback'),
    'callback_middleware' => env('BILLING_CALLBACK_MIDDLEWARE', 'web'),
    
    /*
    |--------------------------------------------------------------------------
    | Redirect URLs
    |--------------------------------------------------------------------------
    |
    | The default URLs to redirect to after a successful or failed
    | payment verification on the callback.
    |
    */
    'redirects' => [
        'success' => env('BILLING_SUCCESS_URL', '/'),
        'failure' => env('BILLING_FAILURE_URL', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billable Model
    |--------------------------------------------------------------------------
    |
    | The model in your application that will be "billable".
    | This is typically your User model.
    |
    */
    'model' => env('BILLING_MODEL', \App\Models\User::class),
];