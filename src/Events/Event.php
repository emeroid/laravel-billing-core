<?php

namespace Emeroid\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class Event
{
    use Dispatchable, SerializesModels;
}