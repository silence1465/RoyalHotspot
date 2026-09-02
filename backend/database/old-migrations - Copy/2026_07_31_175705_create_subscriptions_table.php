<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::create('subscriptions', function (Blueprint $table) {
    $table->id();

    $table->foreignId('customer_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->foreignId('package_id')
    ->constrained('internet_packages');

    $table->foreignId('router_id')
    ->constrained();

    $table->dateTime('starts_at');
    $table->dateTime('expires_at');

    $table->enum('status', [
        'pending',
        'pending_activation',
        'active',
        'expired',
        'cancelled',
        'suspended'
    ]);

    $table->decimal('amount', 10, 2);
    $table->index(['status', 'expires_at']);

    $table->timestamps();

});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
