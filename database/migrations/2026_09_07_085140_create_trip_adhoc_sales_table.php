<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_adhoc_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dispatch_trip_id');
            $table->unsignedBigInteger('customer_id'); // chart_of_accounts — existing or newly-created via /customers
            $table->unsignedBigInteger('existing_sale_invoice_id')->nullable(); // set if this customer already has an invoice on this trip
            $table->string('payment_terms')->default('cash');
            $table->text('remarks')->nullable();
            $table->enum('status', ['pending', 'processed'])->default('pending');
            $table->unsignedBigInteger('processed_sale_invoice_id')->nullable(); // set once office converts this to/adds it to a real invoice
            $table->unsignedBigInteger('created_by'); // delivery manager
            $table->timestamps();

            $table->foreign('dispatch_trip_id')->references('id')->on('dispatch_trips')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('chart_of_accounts');
            $table->foreign('existing_sale_invoice_id')->references('id')->on('sale_invoices')->nullOnDelete();
            $table->foreign('processed_sale_invoice_id')->references('id')->on('sale_invoices')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
        });

        Schema::create('trip_adhoc_sale_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_adhoc_sale_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->decimal('quantity', 15, 2);
            $table->decimal('price', 15, 2);
            $table->timestamps();

            $table->foreign('trip_adhoc_sale_id')->references('id')->on('trip_adhoc_sales')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('variation_id')->references('id')->on('product_variations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_adhoc_sale_items');
        Schema::dropIfExists('trip_adhoc_sales');
    }
};