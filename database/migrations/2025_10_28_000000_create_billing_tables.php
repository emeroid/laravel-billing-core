<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Get the class of the billable model
        $billableModel = app(config('billing.model', \App\Models\User::class));
        $billableTable = $billableModel->getTable();
        $billableForeignKey = $billableModel->getForeignKey();

        // TRANSACTIONS
        Schema::create('transactions', function (Blueprint $table) use ($billableTable, $billableForeignKey) {
            $table->id();
            $table->foreignId($billableForeignKey)->nullable()->constrained($billableTable)->nullOnDelete();
            $table->string('email'); // Store email for guest checkouts
            $table->string('gateway'); // 'paystack', 'paypal'
            $table->string('reference')->unique();
            $table->string('gateway_plan_id')->nullable()->index(); // ID of the plan from the gateway (e.g., pl_..., P-...)
            $table->unsignedBigInteger('amount'); // Store in kobo/cents
            $table->string('currency', 3)->default('NGN');
            $table->string('status'); // 'pending', 'success', 'failed'
            $table->json('gateway_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // PLANS
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // "Pro Plan"
            $table->string('slug')->unique(); // "pro-plan"
            $table->unsignedBigInteger('amount'); // 500000 (e.g., 5000 NGN)
            $table->string('interval'); // 'monthly', 'yearly'
            $table->text('description')->nullable();
            
            // Store gateway-specific IDs
            $table->string('paystack_plan_id')->nullable();
            $table->string('paypal_plan_id')->nullable();
            // ... $table->string('stripe_plan_id')->nullable();

            $table->timestamps();
        });

        // SUBSCRIPTIONS
        Schema::create('subscriptions', function (Blueprint $table) use ($billableTable, $billableForeignKey) {
            $table->id();
            $table->foreignId($billableForeignKey)->constrained($billableTable)->onDelete('cascade');
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('gateway'); // 'paystack', 'paypal'
            $table->string('gateway_subscription_id')->unique(); // The ID from Paystack/PayPal
            $table->string('status'); // 'pending', 'active', 'cancelled', 'past_due'
            
            // --- V2.0 COLUMNS (Critical for swapping) ---
            $table->string('customer_code')->nullable()->comment('Gateway-specific customer ID');
            $table->string('authorization_code')->nullable()->comment('Gateway-specific payment authorization token');
            // --- END V2.0 COLUMNS ---

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable(); // For cancelled subscriptions
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('transactions');
    }
};