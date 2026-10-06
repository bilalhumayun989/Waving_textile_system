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
        Schema::table('fabric_costings', function (Blueprint $table) {
            $table->date('contract_date')->nullable();
            $table->string('contract_no', 60)->nullable();
            $table->string('party_contract_no', 80)->nullable();
            $table->date('delivery_date')->nullable();
            $table->boolean('closed')->default(false);
            $table->string('contract_type', 30)->default('Conversion');
            $table->string('loom_type', 40)->nullable();
            $table->string('buyer_code', 40)->nullable();
            $table->string('buyer_name', 120)->nullable();
            $table->string('buyer_gst', 40)->nullable();
            $table->string('broker_code', 40)->nullable();
            $table->string('broker_name', 120)->nullable();
            $table->decimal('broker_commission_per_meter', 14, 4)->default(0);
            $table->string('quality_code', 40)->nullable();
            $table->string('contract_quality', 120)->nullable();
            $table->decimal('contract_quality_width', 12, 3)->nullable();
            $table->decimal('panna', 12, 3)->default(1);
            $table->decimal('kp_percentage', 7, 3)->default(0);
            $table->unsignedSmallInteger('kp_days')->default(0);
            $table->decimal('pp_percentage', 7, 3)->default(0);
            $table->unsignedSmallInteger('pp_days')->default(0);
            $table->text('delivery_instructions')->nullable();
            $table->text('payment_instructions')->nullable();
            $table->text('quality_instructions')->nullable();
            $table->text('other_instructions')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fabric_costings', function (Blueprint $table) {
            $table->dropColumn([
                'contract_date', 'contract_no', 'party_contract_no', 'delivery_date', 'closed', 'contract_type', 'loom_type',
                'buyer_code', 'buyer_name', 'buyer_gst', 'broker_code', 'broker_name', 'broker_commission_per_meter',
                'quality_code', 'contract_quality', 'contract_quality_width', 'panna', 'kp_percentage', 'kp_days', 'pp_percentage',
                'pp_days', 'delivery_instructions', 'payment_instructions', 'quality_instructions', 'other_instructions',
            ]);
        });
    }
};
