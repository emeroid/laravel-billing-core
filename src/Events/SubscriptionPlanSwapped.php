<?php

namespace Emeroid\Billing\Events;

use Emeroid\Billing\Models\Subscription;
use Emeroid\Billing\Models\Plan;

class SubscriptionPlanSwapped extends Event
{
    public $subscription;
    public $oldPlan;

    public function __construct(Subscription $subscription, Plan $oldPlan)
    {
        $this->subscription = $subscription;
        $this->oldPlan = $oldPlan;
    }
}