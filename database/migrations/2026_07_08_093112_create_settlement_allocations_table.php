<?php
// database/migrations/2026_07_13_000002_create_settlement_allocations_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('settlement_id');
            $table->unsignedBigInteger('sale_invoice_id');
            $table->decimal('wht_amount', 15, 2)->default(0);
            $table->decimal('returned_value', 15, 2)->default(0); // gross (incl. GST reversal)
            $table->decimal('cash_allocated', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2)->default(0); // remaining unsettled on this invoice
            $table->timestamps();

            $table->foreign('settlement_id')->references('id')->on('settlements')->onDelete('cascade');
            $table->foreign('sale_invoice_id')->references('id')->on('sale_invoices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_allocations');
    }
};