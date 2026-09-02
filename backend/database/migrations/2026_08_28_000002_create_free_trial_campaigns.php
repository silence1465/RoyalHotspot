<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('free_trial_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('package_id')->constrained('internet_packages');
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('free_trial_campaign_id')->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();
            $table->unique(['free_trial_campaign_id', 'customer_id'], 'trial_campaign_customer_unique');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE purchases MODIFY payment_method ENUM('paystack', 'momo', 'admin_grant', 'free_trial') DEFAULT 'momo'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE purchases MODIFY payment_method ENUM('paystack', 'momo', 'admin_grant') DEFAULT 'momo'");
        }
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropUnique('trial_campaign_customer_unique');
            $table->dropConstrainedForeignId('free_trial_campaign_id');
        });
        Schema::dropIfExists('free_trial_campaigns');
    }
};
