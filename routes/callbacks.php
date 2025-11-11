<?php

use Illuminate\Support\Facades\Route;
use Emeroid\Billing\Http\Controllers\CallbackController;

/*
|--------------------------------------------------------------------------
| Billing Callback Routes (User-Facing)
|--------------------------------------------------------------------------
*/

Route::get('{gateway}', [CallbackController::class, 'handleCallback'])->name('billing.callback.gateway');