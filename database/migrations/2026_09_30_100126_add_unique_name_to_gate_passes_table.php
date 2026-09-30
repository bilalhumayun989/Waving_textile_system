<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('gate_passes', 'name')) {
            Schema::table('gate_passes', function (Blueprint $table) {
                $table->string('name')->nullable();
            });
        }
        if (! Schema::hasColumn('gate_passes', 'name_key')) {
            Schema::table('gate_passes', function (Blueprint $table) {
                $table->string('name_key')->nullable();
            });
        }

        DB::table('gate_passes')->orderBy('id')->each(function (object $pass): void {
            $name = 'Legacy gate pass #'.$pass->id;
            DB::table('gate_passes')->where('id', $pass->id)->update(['name' => $name, 'name_key' => mb_strtolower($name)]);
        });

        Schema::table('gate_passes', function (Blueprint $table) {
            $table->unique('name_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gate_passes', function (Blueprint $table) {
            $table->dropUnique(['name_key']);
            $table->dropColumn(['name', 'name_key']);
        });
    }
};
