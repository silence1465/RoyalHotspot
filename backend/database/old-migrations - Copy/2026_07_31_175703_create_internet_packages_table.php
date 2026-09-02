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
        Schema::create('internet_packages', function (Blueprint $table) {

            $table->id();

            $table->string('name');

            $table->decimal('price',10,2);

            $table->integer('duration_value');

            $table->enum('duration_unit',[
                'minutes',
                'hours',
                'days',
                'weeks',
                'months'
            ]);

            $table->string('speed_limit');

            $table->bigInteger('data_limit')->nullable();

            $table->enum('status',[
                'active',
                'inactive'
            ])->default('active');

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('internet_packages');
    }
};
