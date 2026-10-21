<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI credit packs an organization bought (one row per paid Stripe Checkout
 * session). Written only by the Stripe webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Who started the checkout; kept when they leave.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pack');
            $table->decimal('amount_usd', 10, 2);
            // Stripe retries webhooks: one grant per paid session.
            $table->string('stripe_checkout_session_id')->unique();
            $table->timestamps();

            // AiBudget sums a month's credits on every AI call.
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_purchases');
    }
};
