<?php
// database/migrations/2026_07_12_000003_create_dispatch_trip_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_trip_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dispatch_trip_id');
            $table->unsignedBigInteger('sale_order_id');
            $table->timestamps();

            $table->foreign('dispatch_trip_id')->references('id')->on('dispatch_trips')->onDelete('cascade');
            $table->foreign('sale_order_id')->references('id')->on('sale_orders')->onDelete('cascade');
            $table->unique(['dispatch_trip_id', 'sale_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_trip_orders');
    }
};