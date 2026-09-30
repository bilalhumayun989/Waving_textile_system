<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TextileService
{
    public static function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    public function insert(string $table, array $data): int
    {
        return DB::table($table)->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function post(string $action, array $input, int $userId): int
    {
        Validator::make($input, ['submission_key' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($action, $input, $userId) {
            $inserted = DB::table('submission_keys')->insertOrIgnore([
                'key' => $input['submission_key'], 'user_id' => $userId, 'action' => $action,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $inserted) {
                return 0;
            }
            $id = match ($action) {
                'customers' => $this->customer($input),
                'invoices' => $this->invoice($input),
                'receipts' => $this->receipt($input),
                'accounts' => $this->account($input),
                'employees' => $this->employee($input),
                'attendance' => $this->attendance($input),
                'payrolls' => $this->payroll($input),
                'pay-salary' => $this->paySalary($input),
                'expenses' => $this->expense($input),
                'fixed-expenses' => $this->fixedExpense($input),
                'pay-fixed' => $this->payFixed($input),
                'defer-fixed' => $this->deferFixed($input),
                'gate-passes' => $this->gatePass($input),
                'void-invoice' => $this->voidInvoice($input),
                'manual-entry' => $this->manualEntry($input),
                'update-customer' => $this->updateCustomer($input),
                'update-employee' => $this->updateEmployee($input),
                'toggle-fixed' => $this->toggleFixed($input),
                default => abort(404),
            };
            $this->insert('audit_logs', ['user_id' => $userId, 'action' => $action,
                'entity' => $action, 'entity_id' => $id,
                'details' => json_encode(collect($input)->except(['submission_key'])->all()),
            ]);

            return $id;
        }, 3);
    }

    public function customer(array $input): int
    {
        $input['phone'] = preg_replace('/[^0-9+]/', '', $input['phone'] ?? '');
        $data = Validator::make($input, ['name' => 'required|string|max:120', 'phone' => 'required|string|max:30|unique:customers',
            'email' => 'nullable|email|max:160', 'address' => 'nullable|string|max:500', 'tax_id' => 'nullable|string|max:80'])->validate();

        return $this->insert('customers', $data);
    }

    public function invoice(array $input): int
    {
        $data = Validator::make($input, [
            'customer_id' => 'required|exists:customers,id', 'date' => 'required|date_format:Y-m-d',
            'due_date' => 'required|date_format:Y-m-d|after_or_equal:date', 'notes' => 'nullable|string|max:1000',
            'discount' => 'nullable|numeric|min:0|max:100000000', 'items' => 'required|array|min:1|max:100',
            'items.*.description' => 'required|string|max:200', 'items.*.unit' => 'required|string|max:30',
            'items.*.quantity' => 'required|numeric|decimal:0,3|min:0.001|max:1000000', 'items.*.rate' => 'required|numeric|min:0.01|max:10000000',
        ])->validate();
        if (DB::table('customers')->where('id', $data['customer_id'])->value('status') !== 'Active') {
            $this->fail('customer_id', 'Select an active customer.');
        }
        $items = array_map(fn ($row) => [...$row, 'rate' => self::cents($row['rate']),
            'amount' => (int) round(round((float) $row['quantity'], 3) * self::cents($row['rate']))], $data['items']);
        $subtotal = array_sum(array_column($items, 'amount'));
        $discount = self::cents($data['discount'] ?? 0);
        if ($discount >= $subtotal) {
            $this->fail('discount', 'Discount must be less than the subtotal.');
        }
        $id = $this->insert('invoices', ['customer_id' => $data['customer_id'], 'date' => $data['date'], 'due_date' => $data['due_date'],
            'notes' => $data['notes'] ?? null, 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $subtotal - $discount]);
        foreach ($items as $item) {
            DB::table('invoice_items')->insert(['invoice_id' => $id, ...$item]);
        }

        return $id;
    }

    public function receipt(array $input): int
    {
        $data = Validator::make($input, ['customer_id' => 'required|exists:customers,id', 'account_id' => 'required|exists:accounts,id',
            'date' => 'required|date_format:Y-m-d', 'amount' => 'required|numeric|min:0.01|max:100000000',
            'method' => 'required|in:Cash,Bank transfer,Cheque,Card,Other', 'reference' => 'nullable|string|max:200',
            'allocation_mode' => 'required|in:oldest,manual', 'allocations' => 'nullable|array',
            'allocations.*.invoice_id' => 'required|integer|distinct|exists:invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0|max:100000000'])->validate();
        DB::table('customers')->where('id', $data['customer_id'])->lockForUpdate()->first();
        $invoices = DB::table('invoices')->where('customer_id', $data['customer_id'])->where('status', 'Posted')->orderBy('date')->orderBy('id')->lockForUpdate()->get();
        $remaining = self::cents($data['amount']);
        $allocations = [];
        if ($data['allocation_mode'] === 'manual') {
            foreach ($data['allocations'] ?? [] as $row) {
                $invoice = $invoices->firstWhere('id', (int) $row['invoice_id']);
                if (! $invoice) {
                    $this->fail('allocations', 'Each allocation must belong to this customer and a posted invoice.');
                }
                $amount = self::cents($row['amount']);
                $due = $invoice->total - DB::table('allocations')->where('invoice_id', $invoice->id)->sum('amount');
                if ($amount > $due || $amount > $remaining) {
                    $this->fail('allocations', 'Allocation exceeds invoice due or the payment amount.');
                }
                if ($amount > 0) {
                    $allocations[] = ['invoice_id' => $invoice->id, 'amount' => $amount];
                    $remaining -= $amount;
                }
            }
        } else {
            foreach ($invoices as $invoice) {
                $due = $invoice->total - DB::table('allocations')->where('invoice_id', $invoice->id)->sum('amount');
                $amount = min($due, $remaining);
                if ($amount > 0) {
                    $allocations[] = ['invoice_id' => $invoice->id, 'amount' => $amount];
                    $remaining -= $amount;
                }
            }
        }
        $id = $this->insert('receipts', collect($data)->except(['allocation_mode', 'allocations'])->merge([
            'amount' => self::cents($data['amount']), 'credit' => $remaining])->all());
        foreach ($allocations as $allocation) {
            DB::table('allocations')->insert(['receipt_id' => $id, ...$allocation]);
        }
        $this->movement((int) $data['account_id'], $data['date'], 'in', self::cents($data['amount']), 'receipts', $id, 'Customer payment');

        return $id;
    }

    public function movement(int $account, string $date, string $type, int $amount, string $source, int $id, string $description): int
    {
        return $this->insert('transactions', ['account_id' => $account, 'date' => $date, 'type' => $type, 'amount' => $amount,
            'source_type' => $source, 'source_id' => $id, 'description' => $description]);
    }

    public function account(array $input): int
    {
        $data = Validator::make($input, ['name' => 'required|string|max:100', 'type' => 'required|in:Cash,Bank',
            'number' => 'nullable|string|max:40', 'opening_balance' => 'required|numeric|min:0|max:100000000'])->validate();
        $data['opening_balance'] = self::cents($data['opening_balance']);

        return $this->insert('accounts', $data);
    }

    public function employee(array $input): int
    {
        $data = Validator::make($input, ['name' => 'required|string|max:120', 'phone' => 'required|string|max:30',
            'department' => 'required|string|max:80', 'designation' => 'required|string|max:80', 'joining_date' => 'required|date_format:Y-m-d',
            'salary_type' => 'required|in:Monthly,Daily', 'salary' => 'required|numeric|min:0.01|max:10000000'])->validate();
        $data['salary'] = self::cents($data['salary']);

        return $this->insert('employees', $data);
    }

    public function attendance(array $input): int
    {
        $data = Validator::make($input, ['date' => 'required|date_format:Y-m-d', 'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'required|distinct|exists:employees,id', 'status' => 'required|in:Present,Absent,Leave,Half Day,Off',
            'check_in' => 'nullable|date_format:H:i', 'check_out' => 'nullable|date_format:H:i|after:check_in',
            'overtime' => 'nullable|numeric|min:0|max:24', 'remarks' => 'nullable|string|max:500'])->validate();
        foreach ($data['employee_ids'] as $employee) {
            if (DB::table('payrolls')->where('employee_id', $employee)->where('period', substr($data['date'], 0, 7))->where('paid', '>', 0)->exists()) {
                $this->fail('date', 'Attendance is locked because this month has a salary payment.');
            }
            DB::table('attendance')->updateOrInsert(['employee_id' => $employee, 'date' => $data['date']],
                [...collect($data)->except(['employee_ids'])->all(), 'overtime' => $data['overtime'] ?? 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        return (int) $data['employee_ids'][0];
    }

    public function payroll(array $input): int
    {
        $data = Validator::make($input, ['employee_id' => 'required|exists:employees,id', 'period' => 'required|date_format:Y-m',
            'allowances' => 'nullable|numeric|min:0|max:10000000', 'deductions' => 'nullable|numeric|min:0|max:10000000',
            'overtime_rate' => 'nullable|numeric|min:0|max:100000'])->validate();
        $employee = DB::table('employees')->where('id', $data['employee_id'])->first();
        $existing = DB::table('payrolls')->where('employee_id', $employee->id)->where('period', $data['period'])->lockForUpdate()->first();
        if ($existing && $existing->paid > 0) {
            $this->fail('period', 'A paid payroll is locked and cannot be recalculated.');
        }
        $start = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth();
        $rows = DB::table('attendance')->where('employee_id', $employee->id)->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])->get();
        if ($rows->isEmpty()) {
            $this->fail('period', 'Mark attendance for this employee before generating payroll.');
        }
        $counts = $rows->countBy('status')->all();
        $days = $start->daysInMonth;
        $eligible = max(0, $days - max(0, Carbon::parse($employee->joining_date)->diffInDays($start, false) * -1));
        $eligible = min($days, $eligible);
        $earned = $employee->salary_type === 'Daily'
            ? (int) round($employee->salary * (($counts['Present'] ?? 0) + ($counts['Half Day'] ?? 0) * 0.5))
            : (int) round($employee->salary / $days * max(0, $eligible - ($counts['Absent'] ?? 0) - ($counts['Half Day'] ?? 0) * 0.5));
        $allowances = self::cents($data['allowances'] ?? 0);
        $deductions = self::cents($data['deductions'] ?? 0);
        $overtime = (int) round($rows->sum('overtime') * self::cents($data['overtime_rate'] ?? 0));
        if ($earned + $allowances + $overtime < $deductions) {
            $this->fail('deductions', 'Deductions cannot exceed earnings.');
        }
        $record = ['employee_id' => $employee->id, 'period' => $data['period'], 'attendance_summary' => json_encode([...$counts, 'overtime' => $rows->sum('overtime')]),
            'earned' => $earned, 'allowances' => $allowances, 'deductions' => $deductions, 'overtime_pay' => $overtime,
            'net' => $earned + $allowances + $overtime - $deductions];
        if ($existing) {
            DB::table('payrolls')->where('id', $existing->id)->update([...$record, 'updated_at' => now()]);

            return $existing->id;
        }

        return $this->insert('payrolls', $record);
    }

    public function paySalary(array $input): int
    {
        $data = Validator::make($input, ['payroll_id' => 'required|exists:payrolls,id', 'account_id' => 'required|exists:accounts,id',
            'date' => 'required|date_format:Y-m-d', 'amount' => 'required|numeric|min:0.01|max:100000000'])->validate();
        $payroll = DB::table('payrolls')->where('id', $data['payroll_id'])->lockForUpdate()->first();
        $amount = self::cents($data['amount']);
        if ($amount > $payroll->net - $payroll->paid) {
            $this->fail('amount', 'Payment exceeds the unpaid salary.');
        }
        DB::table('payrolls')->where('id', $payroll->id)->update(['paid' => $payroll->paid + $amount, 'updated_at' => now()]);
        $this->movement((int) $data['account_id'], $data['date'], 'out', $amount, 'payrolls', $payroll->id, 'Salary ? '.$payroll->period);

        return $payroll->id;
    }

    public function expense(array $input): int
    {
        $data = Validator::make($input, ['account_id' => 'required|exists:accounts,id', 'date' => 'required|date_format:Y-m-d',
            'category' => 'required|string|max:80', 'description' => 'required|string|max:500', 'amount' => 'required|numeric|min:0.01|max:100000000',
            'party' => 'nullable|string|max:120', 'reference' => 'nullable|string|max:150'])->validate();
        $data['amount'] = self::cents($data['amount']);
        $id = $this->insert('expenses', [...$data, 'fixed_expense_id' => $input['_fixed_id'] ?? null]);
        $this->movement((int) $data['account_id'], $data['date'], 'out', $data['amount'], 'expenses', $id, $data['description']);

        return $id;
    }

    public function fixedExpense(array $input): int
    {
        $data = Validator::make($input, ['name' => 'required|string|max:120', 'category' => 'required|string|max:80',
            'amount' => 'required|numeric|min:0.01|max:100000000', 'next_due' => 'required|date_format:Y-m-d',
            'frequency' => 'required|in:Monthly,Quarterly,Yearly', 'account_id' => 'nullable|exists:accounts,id'])->validate();
        $data['amount'] = self::cents($data['amount']);

        return $this->insert('fixed_expenses', $data);
    }

    public function payFixed(array $input): int
    {
        Validator::make($input, ['fixed_expense_id' => 'required|exists:fixed_expenses,id', 'expected_due' => 'required|date_format:Y-m-d'])->validate();
        $fixed = DB::table('fixed_expenses')->where('id', $input['fixed_expense_id'])->lockForUpdate()->first();
        if (! $fixed->active || $fixed->next_due !== $input['expected_due']) {
            $this->fail('fixed_expense_id', 'This recurring period was already handled or is inactive. Refresh the page.');
        }
        $id = $this->expense([...$input, '_fixed_id' => $fixed->id, 'category' => $fixed->category, 'description' => $fixed->name.' ? '.$fixed->next_due]);
        $next = Carbon::parse($fixed->next_due)->addMonthsNoOverflow(match ($fixed->frequency) {
            'Quarterly' => 3, 'Yearly' => 12, default => 1
        });
        DB::table('fixed_expenses')->where('id', $fixed->id)->update(['next_due' => $next->toDateString(), 'updated_at' => now()]);

        return $id;
    }

    public function deferFixed(array $input): int
    {
        $data = Validator::make($input, ['fixed_expense_id' => 'required|exists:fixed_expenses,id', 'next_due' => 'required|date_format:Y-m-d|after:today', 'reason' => 'required|string|max:500'])->validate();
        DB::table('fixed_expenses')->where('id', $data['fixed_expense_id'])->update(['next_due' => $data['next_due'], 'updated_at' => now()]);

        return (int) $data['fixed_expense_id'];
    }

    public function gatePass(array $input): int
    {
        $data = Validator::make($input, ['customer_id' => 'nullable|exists:customers,id', 'invoice_id' => 'nullable|exists:invoices,id',
            'date' => 'required|date_format:Y-m-d', 'type' => 'required|in:Outward,Inward', 'party' => 'nullable|string|max:120',
            'vehicle' => 'nullable|string|max:80', 'driver' => 'nullable|string|max:120', 'description' => 'required|string|max:1000',
            'quantity' => 'required|numeric|min:0.001|max:1000000', 'unit' => 'required|string|max:30', 'purpose' => 'required|string|max:500',
            'authorised_by' => 'required|string|max:120'])->validate();
        if (! empty($data['invoice_id'])) {
            $invoice = DB::table('invoices')->where('id', $data['invoice_id'])->first();
            if ($invoice->status !== 'Posted') {
                $this->fail('invoice_id', 'A voided invoice cannot be dispatched.');
            }
            if (! empty($data['customer_id']) && $data['customer_id'] != $invoice->customer_id) {
                $this->fail('customer_id', 'Customer must match the linked invoice.');
            }
            $data['customer_id'] = $invoice->customer_id;
        }

        return $this->insert('gate_passes', $data);
    }

    public function voidInvoice(array $input): int
    {
        $data = Validator::make($input, ['invoice_id' => 'required|exists:invoices,id', 'reason' => 'required|string|min:5|max:500'])->validate();
        $invoice = DB::table('invoices')->where('id', $data['invoice_id'])->lockForUpdate()->first();
        if ($invoice->status === 'Voided') {
            $this->fail('invoice_id', 'Invoice is already voided.');
        }
        $allocations = DB::table('allocations')->where('invoice_id', $invoice->id)->get();
        $this->insert('audit_logs', ['user_id' => auth()->id(), 'action' => 'allocation-release', 'entity' => 'invoices', 'entity_id' => $invoice->id, 'details' => json_encode(['reason' => $data['reason'], 'released_allocations' => $allocations])]);
        foreach ($allocations as $allocation) {
            DB::table('receipts')->where('id', $allocation->receipt_id)->increment('credit', $allocation->amount);
        }
        // Receipt cash remains intact; allocations become customer advance on a void.
        DB::table('allocations')->where('invoice_id', $invoice->id)->delete();
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'Voided', 'void_reason' => $data['reason'], 'updated_at' => now()]);

        return $invoice->id;
    }

    public function manualEntry(array $input): int
    {
        $data = Validator::make($input, ['account_id' => 'required|exists:accounts,id', 'date' => 'required|date_format:Y-m-d',
            'type' => 'required|in:in,out', 'amount' => 'required|numeric|min:0.01|max:100000000', 'description' => 'required|string|min:5|max:500'])->validate();
        $id = $this->movement((int) $data['account_id'], $data['date'], $data['type'], self::cents($data['amount']), 'transactions', 0, $data['description']);
        DB::table('transactions')->where('id', $id)->update(['source_id' => $id]);

        return $id;
    }

    public function updateCustomer(array $input): int
    {
        $data = Validator::make($input, ['id' => 'required|exists:customers,id', 'status' => 'required|in:Active,Inactive'])->validate();
        DB::table('customers')->where('id', $data['id'])->update(['status' => $data['status'], 'updated_at' => now()]);

        return (int) $data['id'];
    }

    public function updateEmployee(array $input): int
    {
        $data = Validator::make($input, ['id' => 'required|exists:employees,id', 'status' => 'required|in:Active,Inactive'])->validate();
        DB::table('employees')->where('id', $data['id'])->update(['status' => $data['status'], 'updated_at' => now()]);

        return (int) $data['id'];
    }

    public function toggleFixed(array $input): int
    {
        $data = Validator::make($input, ['id' => 'required|exists:fixed_expenses,id', 'active' => 'required|boolean'])->validate();
        DB::table('fixed_expenses')->where('id', $data['id'])->update(['active' => $data['active'], 'updated_at' => now()]);

        return (int) $data['id'];
    }

    public function snapshot(): array
    {
        $data = ['production' => app(ProductionReport::class)->records()];
        foreach (['customers', 'accounts', 'invoices', 'receipts', 'employees', 'attendance', 'payrolls', 'expenses', 'fixed_expenses', 'gate_passes', 'transactions', 'audit_logs'] as $table) {
            $data[$table] = DB::table($table)->orderByDesc('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $data['invoice_items'] = DB::table('invoice_items')->get()->map(fn ($r) => (array) $r)->all();
        $data['allocations'] = DB::table('allocations')->get()->map(fn ($r) => (array) $r)->all();
        foreach ($data['invoices'] as &$invoice) {
            $invoice['paid'] = collect($data['allocations'])->where('invoice_id', $invoice['id'])->sum('amount');
            $invoice['due'] = $invoice['status'] === 'Voided' ? 0 : $invoice['total'] - $invoice['paid'];
            $invoice['payment_status'] = $invoice['status'] === 'Voided' ? 'Voided' : ($invoice['due'] === 0 ? 'Paid' : ($invoice['paid'] > 0 ? 'Partially Paid' : 'Unpaid'));
        }
        unset($invoice);
        foreach ($data['customers'] as &$customer) {
            $invoices = collect($data['invoices'])->where('customer_id', $customer['id'])->where('status', 'Posted');
            $receipts = collect($data['receipts'])->where('customer_id', $customer['id']);
            $customer['balance'] = $customer['opening_balance'] + $invoices->sum('total') - $receipts->sum('amount');
            $customer['credit'] = $receipts->sum('credit');
            $customer['last_invoice'] = $invoices->max('date');
            $customer['last_payment'] = $receipts->max('date');
        }
        unset($customer);
        foreach ($data['accounts'] as &$account) {
            $rows = collect($data['transactions'])->where('account_id', $account['id']);
            $account['money_in'] = $rows->where('type', 'in')->sum('amount');
            $account['money_out'] = $rows->where('type', 'out')->sum('amount');
            $account['balance'] = $account['opening_balance'] + $account['money_in'] - $account['money_out'];
        }

        return $data;
    }
}
