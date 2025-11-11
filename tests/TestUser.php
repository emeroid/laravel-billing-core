<?php

namespace Emeroid\Billing\Tests;

use Illuminate\Foundation\Auth\User;
use Emeroid\Billing\Traits\Billable;

/**
 * We need a separate file for this model to be auto-discoverable
 * by Laravel's component discovery in some test environments.
 */
class TestUser extends User
{
    use Billable;
    protected $guarded = [];
    protected $table = 'users';
}