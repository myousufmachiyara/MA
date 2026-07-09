<?php
// database/migrations/2026_07_10_000002_create_sale_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 10)->unique()->nullable(); // assigned on sync/confirm, not at booking

            $table->unsignedBigInteger('customer_id');   // chart_of_accounts (account_type = customer)
            $table->unsignedBigInteger('booker_id');      // users (user_type = mobile)

            $table->date('order_date');
            $table->enum('status', ['draft', 'confirmed', 'merged', 'invoiced', 'cancelled'])->default('draft');

            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_quantity', 15, 2)->default(0);

            $table->enum('payment_terms', ['cash', 'credit'])->default('cash');
            $table->text('remarks')->nullable();

            // ── Offline-first sync fields ────────────────────────────
            $table->uuid('local_uuid')->unique();
            $table->enum('sync_status', ['pending', 'synced'])->default('synced');
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('customer_id')->references('id')->on('chart_of_accounts')->onDelete('cascade');
            $table->foreign('booker_id')->references('id')->on('users')->onDelete('cascade');

            $table->index('status');
            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_orders');
    }
};