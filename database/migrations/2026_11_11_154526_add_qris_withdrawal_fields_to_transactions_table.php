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
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('transaction_type')->default('SALE')->after('invoice_no'); // SALE, CASH_WITHDRAWAL
            $table->integer('admin_fee')->default(0)->after('grand_total'); // Jasa tarik tunai / overpayment fee
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['transaction_type', 'admin_fee']);
        });
    }
};
