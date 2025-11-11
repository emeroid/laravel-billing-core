<?php

namespace Emeroid\Billing\Events;

use Emeroid\Billing\Models\Transaction;

class TransactionFailed extends Event
{
    public $transaction;

    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }
}