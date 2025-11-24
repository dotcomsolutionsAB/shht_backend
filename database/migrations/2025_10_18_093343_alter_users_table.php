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
        Schema::table('users', function (Blueprint $table) {
            //
            // Add the new columns
            $table->string('username')->unique()->after('password');
            $table->enum('role', ['admin', 'sales', 'staff', 'dispatch'])->after('username');
            $table->string('mobile')->after('role');
            $table->enum('order_views', ['self', 'global'])->default('self')->after('mobile');
            $table->enum('change_status', ['0', '1'])->default('0')->after('order_views');
            // NEW FIELDS
            $table->enum('whatsapp_status', ['yes', 'no'])->default('no')->after('change_status');
            $table->enum('email_status', ['yes', 'no'])->default('no')->after('whatsapp');
            // Make the email column nullable
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
