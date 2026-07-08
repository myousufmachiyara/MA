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
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_no')->unique();
            $table->string('voucher_type', 50); // 'purchase', 'sale', 'sale_return', 'payment', 'receipt', 'journal', etc.
            $table->date('voucher_date');

            // Polymorphic reference to the source document (Purchase Invoice, Sale Invoice, Settlement, etc.)
            // Null for manually-created vouchers via the Journal/Payment/Receipt screens.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // Simple vouchers (single Dr/Cr pair) — used for manual payment/receipt/journal entries.
            // Auto-generated multi-line vouchers leave these null and use accounting_entries instead.
            $table->unsignedBigInteger('ac_dr_sid')->nullable();
            $table->unsignedBigInteger('ac_cr_sid')->nullable();
            $table->decimal('amount', 15, 2)->nullable();

            $table->text('remarks')->nullable();
            $table->json('attachments')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('ac_dr_sid')->references('id')->on('chart_of_accounts')->onDelete('restrict');
            $table->foreign('ac_cr_sid')->references('id')->on('chart_of_accounts')->onDelete('restrict');

            $table->index(['reference_type', 'reference_id']);
            $table->index('voucher_date');
            $table->index('voucher_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};