<?php

namespace Emeroid\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gateway_response' => 'array',
        'paid_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(config('billing.model'));
    }

    public function plan()
    {
        // This relationship finds the Plan by matching the gateway's plan ID
        // from this transaction against any of the plan ID columns in the plans table.
        return $this->belongsTo(Plan::class, 'gateway_plan_id', 'paystack_plan_id')
                    ->orWhere(fn($q) => $q->on('transactions.gateway_plan_id', '=', 'plans.paypal_plan_id'));
        
        // You could add more ->orWhere(...) clauses as you add more drivers
    }
}