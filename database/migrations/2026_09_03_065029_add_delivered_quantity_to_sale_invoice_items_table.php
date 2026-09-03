<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoice_items', function (Blueprint $table) {
            $table->decimal('delivered_quantity', 15, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoice_items', function (Blueprint $table) {
            $table->dropColumn('delivered_quantity');
        });
    }
};