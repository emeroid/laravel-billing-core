<?php

namespace Emeroid\Billing\Events;

use Emeroid\Billing\Models\Transaction;

class TransactionSuccessful extends Event
{
    public $transaction;

    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }
}