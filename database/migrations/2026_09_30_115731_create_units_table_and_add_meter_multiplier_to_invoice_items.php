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
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_key')->unique();
            $table->decimal('meters_per_unit', 12, 6);
            $table->timestamps();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('unit_multiplier', 12, 6)->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('unit_multiplier');
        });

        Schema::dropIfExists('units');
    }
};
