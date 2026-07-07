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
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->date('return_date');
            $table->string('sale_invoice_no', 50)->nullable(); // invoice reference
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('chart_of_accounts')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_returns');
    }
};
