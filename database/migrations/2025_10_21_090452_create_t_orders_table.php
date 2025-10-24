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
        Schema::create('t_orders', function (Blueprint $table) {
            $table->id();
            // Client and related info
            $table->enum('company', [
                'SHHT',
                'SHAPL'
            ]);
            $table->unsignedBigInteger('client');
            $table->unsignedBigInteger('client_contact_person');

            // Sales Order and Order details
            $table->string('so_no')->unique();
            $table->date('so_date');
            $table->string('order_no')->unique();
            $table->date('order_date');

            // Invoice relation
            $table->unsignedBigInteger('invoice')->nullable();

            // Order status
            $table->enum('status', [
                'pending',
                'dispatched',
                'partial_pending',
                'invoiced',
                'completed',
                'short_closed',
                'cancelled',
                'out_of_stock'
            ])->default('pending');

            // Tracking users
            $table->unsignedBigInteger('initiated_by');
            $table->unsignedBigInteger('checked_by');
            $table->unsignedBigInteger('dispatched_by');

            // Drive link
            $table->string('drive_link')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_orders');
    }
};
