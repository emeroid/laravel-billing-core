<?php

namespace Emeroid\Billing\Drivers;

use Emeroid\Billing\Models\Transaction;

abstract class AbstractDriver
{
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    protected function findBillableByEmail(string $email)
    {
        $modelClass = config('billing.model');
        return $modelClass::where('email', $email)->first();
    }

    protected function createPendingTransaction(
        string $reference,
        int $amount,
        string $email,
        string $gateway,
        ?int $userId = null,
        string $currency = 'USD',
        ?string $planId = null
    ) {
        if (!$userId) {
            $user = $this->findBillableByEmail($email);
            $userId = $user->id ?? null;
        }

        return Transaction::create([
            'user_id' => $userId,
            'reference' => $reference,
            'gateway' => $gateway,
            'amount' => $amount,
            'status' => 'pending',
            'email' => $email,
            'currency' => $currency,
            'gateway_plan_id' => $planId,
        ]);
    }
}