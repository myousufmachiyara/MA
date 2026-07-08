<?php
// database/migrations/2026_07_13_000003_create_settlement_return_items_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('settlement_allocation_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->decimal('quantity', 15, 2);
            $table->decimal('price', 15, 2);       // from original invoice line
            $table->decimal('cost_price', 15, 2);  // from original invoice line
            $table->decimal('line_value', 15, 2);  // qty * price (net)
            $table->timestamps();

            $table->foreign('settlement_allocation_id')->references('id')->on('settlement_allocations')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('product_variations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_return_items');
    }
};