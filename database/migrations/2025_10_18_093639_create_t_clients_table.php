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
        Schema::create('t_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('category');
            $table->unsignedBigInteger('sub_category');
            $table->string('tags');
            $table->string('city');
            $table->string('state');
            $table->integer('pincode');
            $table->unsignedBigInteger('rm');
            $table->unsignedBigInteger('sales_person');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('t_clients');
    }
};
