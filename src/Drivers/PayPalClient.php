<?php

namespace Emeroid\Billing\Drivers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A Guzzle-based client for interacting with the PayPal API.
 * This client handles its own authentication and token caching.
 */
class PayPalClient
{
    protected $http;
    protected $config;
    protected $baseUrl;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->baseUrl = $config['mode'] === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        $this->http = Http::baseUrl($this->baseUrl)
            ->withHeaders(['Accept' => 'application/json']);
    }

    /**
     * Get a valid OAuth2 token from PayPal, caching it.
     */
    protected function getAuthToken(): string
    {
        $cacheKey = 'paypal.auth.token.' . md5($this->config['client_id']);

        // Return cached token if available
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Get a new token
        $response = $this->http
            ->asForm()
            ->withBasicAuth($this->config['client_id'], $this->config['secret'])
            ->post('/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        $data = $response->json();

        if (!$response->successful() || !isset($data['access_token'])) {
            throw new \Exception('PayPal: Could not retrieve auth token.');
        }

        // Cache the token for just under its expiry time
        $expiresIn = $data['expires_in'] - 300; // Cache for 5 mins less than expiry
        Cache::put($cacheKey, $data['access_token'], $expiresIn);

        return $data['access_token'];
    }

    /**
     * Make an authenticated request to the PayPal API.
     */
    protected function request(string $method, string $endpoint, array $data = [])
    {
        $token = $this->getAuthToken();

        $request = $this->http
            ->withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'PayPal-Request-Id' => uniqid(),
            ]);

        if (strtoupper($method) === 'POST' && $endpoint === '/v1/billing/subscriptions') {
            // Subscriptions API has a specific header requirement for idempotency
            $request->withHeaders(['Prefer' => 'return=representation']);
        }
        
        $response = $request->{strtolower($method)}($endpoint, $data);

        if (!$response->successful()) {
            // Log the error details
            report("PayPal API Error: {$response->body()}", $data);

            // Throw a general exception
            throw new \Exception('PayPal API Error: ' . $response->json('message', 'Unknown error.'));
        }

        return $response;
    }

    // --- Public API Methods ---

    public function post(string $endpoint, array $data = [])
    {
        return $this->request('post', $endpoint, $data);
    }

    public function get(string $endpoint)
    {
        return $this->request('get', $endpoint);
    }

    public function patch(string $endpoint, array $data)
    {
        return $this->request('patch', $endpoint, $data);
    }
}