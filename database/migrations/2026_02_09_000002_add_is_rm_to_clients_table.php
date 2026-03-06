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
        Schema::table('t_clients', function (Blueprint $table) {
            $table->boolean('is_rm')->default(false)->after('sales_person');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('t_clients', function (Blueprint $table) {
            $table->dropColumn('is_rm');
        });
    }
};
