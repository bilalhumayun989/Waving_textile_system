<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'customers', 'accounts', 'invoices', 'invoice_items', 'receipts', 'allocations', 'employees',
        'attendance', 'payrolls', 'fixed_expenses', 'expenses', 'gate_passes', 'transactions',
        'audit_logs', 'units', 'account_types', 'submission_keys',
    ];

    public function up(): void
    {
        $configuredNames = array_map(fn (string $name): string => mb_strtolower(trim($name)), config('workspace.super_admin_names', []));
        $legacyOwner = DB::table('users')->where('role', 'admin')->orderBy('id')->get()->first(
            fn (object $user): bool => in_array(mb_strtolower(trim($user->name)), $configuredNames, true)
        )?->id ?? DB::table('users')->where('role', 'admin')->orderBy('id')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');

        foreach ($this->tables as $name) {
            if (! Schema::hasTable($name) || Schema::hasColumn($name, 'owner_id')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('owner_id')->nullable()->index();
            });
            if ($legacyOwner) {
                DB::table($name)->whereNull('owner_id')->update(['owner_id' => $legacyOwner]);
            }
        }

        foreach ([['customers', 'phone'], ['gate_passes', 'name_key'], ['units', 'name_key'], ['account_types', 'name_key']] as [$name, $column]) {
            if (! Schema::hasTable($name) || ! Schema::hasColumn($name, $column)) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name, $column): void {
                $table->dropUnique([$column]);
                $table->unique(['owner_id', $column]);
            });
        }
    }

    public function down(): void
    {
        foreach ([['customers', 'phone'], ['gate_passes', 'name_key'], ['units', 'name_key'], ['account_types', 'name_key']] as [$name, $column]) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'owner_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropUnique(['owner_id', $column]));
                Schema::table($name, fn (Blueprint $table) => $table->unique($column));
            }
        }
        foreach (array_reverse($this->tables) as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'owner_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('owner_id'));
            }
        }
    }
};
