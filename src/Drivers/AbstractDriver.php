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

    protected function billableModelClass(): string
    {
        return config('billing.model');
    }

    protected function billableForeignKey(): string
    {
        $model = app($this->billableModelClass());
        return $model->getForeignKey();
    }

    /**
     * Given a Transaction instance return the billable model (or null)
    */
    protected function getBillableFromTransaction(Transaction $transaction)
    {
        $fk = $this->billableForeignKey();
        $id = $transaction->{$fk} ?? null;
        if (!$id) {
            return null;
        }
        return app($this->billableModelClass())->find($id);
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

        $transaction = [
            $this->billableForeignKey() => $userId,
            'reference' => $reference,
            'gateway' => $gateway,
            'amount' => $amount,
            'status' => 'pending',
            'email' => $email,
            'currency' => $currency,
            'gateway_plan_id' => $planId,
        ];

        return Transaction::create($transaction);
    }
}