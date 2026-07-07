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
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id(); // Auto-increment primary key

            $table->string('account_code', 20)->unique();
            $table->unsignedBigInteger('shoa_id'); // Foreign key for sub_head_of_accounts
            $table->string('name'); // Name of the account
            $table->string('trn')->nullable();
            $table->string('account_type')->nullable();

            // ── Customer classification (drives WHT rate & reporting) ──
            $table->enum('customer_type', ['retailer', 'wholesaler'])->nullable();

            // ── Tax profile ─────────────────────────────────────────
            $table->boolean('is_gst_registered')->default(false);
            $table->string('gst_number', 50)->nullable();
            $table->enum('filer_status', ['filer', 'non_filer'])->nullable();
            $table->boolean('wht_applicable')->default(false);
            $table->decimal('wht_rate', 5, 2)->nullable(); // e.g. 4.50 = 4.5%

            $table->decimal('receivables', 15, 2)->default(0);
            $table->decimal('payables', 15, 2)->default(0);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->date('opening_date'); // Opening date for the account

            $table->string('remarks')->nullable();
            $table->string('address')->nullable();
            $table->string('contact_no')->nullable();

            // ── Link a COA account to a system user ─────────────────
            // Used for: Delivery Manager Clearing accounts, booker commission accounts, etc.
            $table->unsignedBigInteger('linked_user_id')->nullable();

            // ── Protect default/system accounts without hardcoded arrays ──
            $table->boolean('is_system_account')->default(false);

            // ── Deactivate without deleting (preserves ledger history) ──
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps(); // Includes created_at and updated_at
            $table->softDeletes(); // Includes deleted_at for soft deletes

            // ── Foreign keys ─────────────────────────────────────────
            $table->foreign('shoa_id')->references('id')->on('sub_head_of_accounts')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('linked_user_id')->references('id')->on('users')->nullOnDelete();

            // ── Indexes ──────────────────────────────────────────────
            $table->index('account_type');
            $table->index('customer_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};