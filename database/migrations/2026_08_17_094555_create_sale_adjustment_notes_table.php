<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_adjustment_notes', function (Blueprint $table) {
            $table->id();
            $table->string('note_no', 10)->unique();
            $table->enum('note_type', ['debit', 'credit']);
            $table->unsignedBigInteger('sale_invoice_id');
            $table->date('note_date');
            $table->decimal('amount', 15, 2);
            $table->string('reason');
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('sale_invoice_id')->references('id')->on('sale_invoices')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
    }
    public function down(): void { Schema::dropIfExists('sale_adjustment_notes'); }
};