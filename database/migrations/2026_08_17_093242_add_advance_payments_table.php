<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advance_payments', function (Blueprint $table) {
            $table->id();
            $table->string('advance_no', 10)->unique();
            $table->unsignedBigInteger('party_id'); // chart_of_accounts (customer or vendor)
            $table->enum('party_type', ['customer', 'vendor']);
            $table->date('payment_date');
            $table->unsignedBigInteger('cash_bank_account_id'); // COA cash/bank account used
            $table->decimal('amount', 15, 2);
            $table->decimal('remaining_amount', 15, 2); // shrinks as it's adjusted against invoices
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('party_id')->references('id')->on('chart_of_accounts')->onDelete('cascade');
            $table->foreign('cash_bank_account_id')->references('id')->on('chart_of_accounts')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void { Schema::dropIfExists('advance_payments'); }
};