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
        Schema::create('fabric_costings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->index();
            $table->foreignId('invoice_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('read', 12, 3);
            $table->decimal('pick', 12, 3);
            $table->decimal('warp_count', 12, 4);
            $table->decimal('weft_count', 12, 4);
            $table->decimal('width', 12, 3);
            $table->decimal('yarn_warp_rate', 14, 4);
            $table->decimal('yarn_weft_rate', 14, 4);
            $table->decimal('conversion_rate', 14, 4);
            foreach (['warp_wt_40m', 'weft_wt_40m', 'warp_weight_1m', 'weft_weight_1m', 'total_weight_1m_lb', 'total_weight_1m_kg', 'width_m', 'warp_bags', 'weft_bags'] as $column) {
                $table->decimal($column, 16, 6)->default(0);
            }
            $table->decimal('gsm', 12, 2)->default(0);
            foreach (['warp_amount_per_mtr', 'weft_amount_per_mtr', 'conversion_per_mtr'] as $column) {
                $table->bigInteger($column)->default(0);
            }
            foreach (['fabric_rate_per_mtr', 'contract_value', 'conv_value', 'yarn_value', 'sales_tax_amount'] as $column) {
                $table->bigInteger($column)->default(0);
            }
            $table->decimal('sale_tax_rate', 6, 4)->default(0.17);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fabric_costings');
    }
};
