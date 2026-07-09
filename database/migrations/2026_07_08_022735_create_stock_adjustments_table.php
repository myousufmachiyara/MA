<?php
// database/migrations/2026_07_11_000001_create_stock_adjustments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no', 10)->unique();
            $table->date('adjustment_date');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->enum('reason_type', ['damage', 'loss', 'theft', 'stock_take_correction', 'other']);
            $table->text('remarks')->nullable();
            $table->decimal('total_increase_value', 15, 2)->default(0);
            $table->decimal('total_decrease_value', 15, 2)->default(0);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};