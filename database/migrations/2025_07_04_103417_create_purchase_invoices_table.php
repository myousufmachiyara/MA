<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 10)->unique();
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->date('invoice_date');
            $table->string('bill_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->text('remarks')->nullable();

            // FIX: totals were referenced in views but never stored
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_quantity', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);

            // Future-proofed tax fields — nullable, unused until GST decision confirmed
            $table->boolean('is_tax_invoice')->default(false);
            $table->enum('gst_type', ['inclusive', 'exclusive'])->nullable();
            $table->decimal('gst_rate', 5, 2)->nullable();
            $table->decimal('gst_amount', 15, 2)->default(0);

            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('vendor_id')->references('id')->on('chart_of_accounts')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};