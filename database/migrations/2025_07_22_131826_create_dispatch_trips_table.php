<?php
// database/migrations/2026_07_12_000002_create_dispatch_trips_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_trips', function (Blueprint $table) {
            $table->id();
            $table->string('trip_no', 10)->unique();
            $table->date('trip_date');
            $table->string('vehicle_no', 50); // "Suzuki details"
            $table->unsignedBigInteger('delivery_manager_id');
            $table->enum('status', ['planned', 'dispatched', 'settled', 'cancelled'])->default('planned');
            $table->unsignedInteger('total_orders')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('delivery_manager_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_trips');
    }
};