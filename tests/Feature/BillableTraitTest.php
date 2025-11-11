<?php

namespace Emeroid\Billing\Tests\Feature;

use Emeroid\Billing\Models\Plan;
use Emeroid\Billing\Models\Subscription;
use Emeroid\Billing\Tests\TestCase;

class BillableTraitTest extends TestCase
{
    protected $plan;
    protected $anotherPlan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'amount' => 50000,
        ]);
        
        $this->anotherPlan = Plan::create([
            'name' => 'Basic Plan',
            'slug' => 'basic-plan',
            'amount' => 10000,
        ]);
    }

    /** @test */
    public function it_can_check_if_subscribed()
    {
        $this->assertFalse($this->user->isSubscribed());
        $this->assertFalse($this->user->hasActiveSubscription());

        Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_1',
            'status' => 'active',
        ]);

        $this->assertTrue($this->user->isSubscribed());
        $this->assertTrue($this->user->hasActiveSubscription());
    }

    /** @test */
    public function it_can_check_if_on_grace_period()
    {
        $this->assertFalse($this->user->onGracePeriod());

        Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_1',
            'status' => 'cancelled',
            'ends_at' => now()->addDays(5),
        ]);

        $this->assertTrue($this->user->onGracePeriod());
        $this->assertFalse($this->user->isSubscribed()); // Not 'active'
        $this->assertTrue($this->user->hasActiveSubscription()); // But should still have access
    }

    /** @test */
    public function it_can_check_if_subscribed_to_a_specific_plan()
    {
        Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'gateway' => 'paystack',
            'gateway_subscription_id' => 'SUB_1',
            'status' => 'active',
        ]);

        $this->assertTrue($this->user->isSubscribedTo('pro-plan'));
        $this->assertFalse($this->user->isSubscribedTo('basic-plan'));
    }
}