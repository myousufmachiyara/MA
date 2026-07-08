<?php
// database/migrations/2026_07_11_000002_create_stock_movement_items_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movement_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_adjustment_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->enum('direction', ['increase', 'decrease']);
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_cost', 15, 2)->default(0); // for valuing the voucher entry
            $table->decimal('stock_before', 15, 2)->default(0);
            $table->decimal('stock_after', 15, 2)->default(0);
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->foreign('stock_adjustment_id')->references('id')->on('stock_adjustments')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('product_variations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movement_items');
    }
};