<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_invoice_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_invoice_id');
            $table->string('file_path');
            $table->timestamps();

            $table->foreign('purchase_invoice_id')->references('id')->on('purchase_invoices')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_attachments');
    }
};
