<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_invoice_id')->nullable()->after('sale_invoice_no');
            $table->foreign('sale_invoice_id')->references('id')->on('sale_invoices')->nullOnDelete();
        });

        // Backfill: match existing string values to real invoice IDs
        DB::table('sale_returns')->whereNotNull('sale_invoice_no')->orderBy('id')->chunk(100, function ($returns) {
            foreach ($returns as $return) {
                $invoice = DB::table('sale_invoices')->where('invoice_no', $return->sale_invoice_no)->first();
                if ($invoice) {
                    DB::table('sale_returns')->where('id', $return->id)->update(['sale_invoice_id' => $invoice->id]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropForeign(['sale_invoice_id']);
            $table->dropColumn('sale_invoice_id');
        });
    }
};