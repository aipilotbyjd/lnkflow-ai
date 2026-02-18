<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_interval', 10)->default('monthly')->after('status');
            $table->unsignedInteger('credits_monthly')->default(0)->after('billing_interval');
            $table->unsignedInteger('credits_yearly_pool')->default(0)->after('credits_monthly');
            $table->string('stripe_price_id')->nullable()->after('stripe_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['billing_interval', 'credits_monthly', 'credits_yearly_pool', 'stripe_price_id']);
        });
    }
};
