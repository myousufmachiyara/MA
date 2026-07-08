<?php
// merged purchase_invoice_items migration

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_invoice_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->unsignedBigInteger('po_item_id')->nullable(); // links back to the PO line, if any
            $table->decimal('quantity', 15, 2);
            $table->unsignedBigInteger('unit');
            $table->decimal('price', 15, 2);
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->foreign('unit')->references('id')->on('measurement_units')->onDelete('cascade');
            $table->foreign('purchase_invoice_id')->references('id')->on('purchase_invoices')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('product_variations')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('po_item_id')->references('id')->on('purchase_order_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');
    }
};