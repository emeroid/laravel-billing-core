<?php

namespace Emeroid\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(config('billing.model'));
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}