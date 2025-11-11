<?php

namespace Emeroid\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
    ];
}