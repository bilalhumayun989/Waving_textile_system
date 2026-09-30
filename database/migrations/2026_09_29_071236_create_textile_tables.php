<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('role')->default('staff'));
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone')->unique();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->string('tax_id')->nullable();
            $t->string('status')->default('Active');
            $t->bigInteger('opening_balance')->default(0);
            $t->timestamps();
        });
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type');
            $t->string('number')->nullable();
            $t->bigInteger('opening_balance')->default(0);
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained();
            $t->date('date');
            $t->date('due_date');
            $t->text('notes')->nullable();
            $t->bigInteger('subtotal');
            $t->bigInteger('discount')->default(0);
            $t->bigInteger('total');
            $t->string('status')->default('Posted');
            $t->text('void_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained();
            $t->string('description');
            $t->string('unit');
            $t->decimal('quantity', 12, 3);
            $t->bigInteger('rate');
            $t->bigInteger('amount');
        });
        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('account_id')->constrained();
            $t->date('date');
            $t->bigInteger('amount');
            $t->bigInteger('credit')->default(0);
            $t->string('method');
            $t->string('reference')->nullable();
            $t->timestamps();
        });
        Schema::create('allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('receipt_id')->constrained();
            $t->foreignId('invoice_id')->constrained();
            $t->bigInteger('amount');
            $t->unique(['receipt_id', 'invoice_id']);
        });
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone');
            $t->string('department');
            $t->string('designation');
            $t->date('joining_date');
            $t->string('salary_type');
            $t->bigInteger('salary');
            $t->string('status')->default('Active');
            $t->timestamps();
        });
        Schema::create('attendance', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained();
            $t->date('date');
            $t->string('status');
            $t->string('check_in')->nullable();
            $t->string('check_out')->nullable();
            $t->decimal('overtime', 8, 2)->default(0);
            $t->text('remarks')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'date']);
        });
        Schema::create('payrolls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained();
            $t->string('period');
            $t->json('attendance_summary');
            $t->bigInteger('earned');
            $t->bigInteger('allowances')->default(0);
            $t->bigInteger('deductions')->default(0);
            $t->bigInteger('overtime_pay')->default(0);
            $t->bigInteger('net');
            $t->bigInteger('paid')->default(0);
            $t->timestamps();
            $t->unique(['employee_id', 'period']);
        });
        Schema::create('fixed_expenses', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('category');
            $t->bigInteger('amount');
            $t->date('next_due');
            $t->string('frequency')->default('Monthly');
            $t->foreignId('account_id')->nullable()->constrained();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fixed_expense_id')->nullable()->constrained();
            $t->foreignId('account_id')->constrained();
            $t->date('date');
            $t->string('category');
            $t->text('description');
            $t->string('party')->nullable();
            $t->string('reference')->nullable();
            $t->bigInteger('amount');
            $t->timestamps();
        });
        Schema::create('gate_passes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->foreignId('invoice_id')->nullable()->constrained();
            $t->date('date');
            $t->string('type');
            $t->string('party')->nullable();
            $t->string('vehicle')->nullable();
            $t->string('driver')->nullable();
            $t->text('description');
            $t->decimal('quantity', 12, 3);
            $t->string('unit');
            $t->string('purpose');
            $t->string('authorised_by');
            $t->timestamps();
        });
        Schema::create('transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained();
            $t->date('date');
            $t->string('type');
            $t->bigInteger('amount');
            $t->string('source_type');
            $t->unsignedBigInteger('source_id');
            $t->text('description');
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action');
            $t->string('entity');
            $t->unsignedBigInteger('entity_id');
            $t->text('details')->nullable();
            $t->timestamps();
        });
        Schema::create('submission_keys', function (Blueprint $t) {
            $t->id();
            $t->uuid('key')->unique();
            $t->foreignId('user_id')->constrained();
            $t->string('action');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['submission_keys', 'audit_logs', 'transactions', 'gate_passes', 'expenses', 'fixed_expenses', 'payrolls', 'attendance', 'employees', 'allocations', 'receipts', 'invoice_items', 'invoices', 'accounts', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
