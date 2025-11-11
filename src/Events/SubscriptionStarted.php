<?php

namespace Emeroid\Billing\Events;

use Emeroid\Billing\Models\Subscription;

class SubscriptionStarted extends Event
{
    public $subscription;

    public function __construct(Subscription $subscription)
    {
        $this->subscription = $subscription;
    }
}