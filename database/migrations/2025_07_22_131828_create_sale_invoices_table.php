<?php
// database/migrations/2026_07_12_000004_create_sale_invoices_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 10)->unique();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('sale_order_id')->nullable();
            $table->unsignedBigInteger('dispatch_trip_id')->nullable();
            $table->date('invoice_date');
            $table->enum('payment_terms', ['cash', 'credit'])->default('cash');

            // ── GST ──────────────────────────────────────────────────
            $table->boolean('is_tax_invoice')->default(false);
            $table->enum('gst_type', ['inclusive', 'exclusive'])->nullable();
            $table->decimal('gst_rate', 5, 2)->nullable();
            $table->decimal('gst_amount', 15, 2)->default(0);

            // ── WHT (informational until Settlement posts it) ────────
            $table->boolean('wht_applicable')->default(false);
            $table->decimal('wht_rate', 5, 2)->nullable();
            $table->decimal('wht_amount', 15, 2)->default(0);

            $table->decimal('total_quantity', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);   // excl. GST
            $table->decimal('total_amount', 15, 2)->default(0); // incl. GST — what customer owes
            $table->decimal('cogs_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);  // updated by Settlement later

            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('customer_id')->references('id')->on('chart_of_accounts')->onDelete('cascade');
            $table->foreign('sale_order_id')->references('id')->on('sale_orders')->nullOnDelete();
            $table->foreign('dispatch_trip_id')->references('id')->on('dispatch_trips')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_invoices');
    }
};