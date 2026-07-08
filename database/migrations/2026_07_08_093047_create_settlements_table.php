<?php
// database/migrations/2026_07_13_000001_create_settlements_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_no', 10)->unique();
            $table->unsignedBigInteger('dispatch_trip_id');
            $table->date('settlement_date');
            $table->decimal('total_cash_received', 15, 2)->default(0);
            $table->decimal('total_returned_value', 15, 2)->default(0);
            $table->decimal('total_wht_amount', 15, 2)->default(0);
            $table->boolean('cleared_to_office')->default(false);
            $table->timestamp('cleared_at')->nullable();
            $table->unsignedBigInteger('cleared_by')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('dispatch_trip_id')->references('id')->on('dispatch_trips')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('cleared_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};