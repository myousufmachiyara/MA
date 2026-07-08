<?php
// database/migrations/2026_07_10_000001_create_stock_movements_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable(); // ready for multi-warehouse
            $table->enum('direction', ['in', 'out']);
            $table->decimal('quantity', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('reference_type', 50); // purchase_invoice, purchase_return, sale_invoice,
                                                    // sale_return, stock_adjustment, stock_transfer
            $table->unsignedBigInteger('reference_id');
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('item_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('variation_id')->references('id')->on('product_variations')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['item_id', 'variation_id']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};