<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advance_payment_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('advance_payment_id');
            $table->string('invoice_type'); // 'sale_invoice' or 'purchase_invoice'
            $table->unsignedBigInteger('invoice_id');
            $table->decimal('amount_adjusted', 15, 2);
            $table->date('adjustment_date');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('advance_payment_id')->references('id')->on('advance_payments')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void { Schema::dropIfExists('advance_payment_adjustments'); }
};