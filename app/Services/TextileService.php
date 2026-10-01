<?php

namespace App\Services;

use App\Models\FabricCosting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TextileService
{
    private ?int $ownerId = null;

    public static function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    public function insert(string $table, array $data): int
    {
        return DB::table($table)->insertGetId([...$data, 'owner_id' => $this->ownerId, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function assertOwnedReferences(string $action, array $input, int $ownerId): void
    {
        $references = [
            'customer_id' => 'customers', 'account_id' => 'accounts', 'invoice_id' => 'invoices',
            'payroll_id' => 'payrolls', 'employee_id' => 'employees', 'fixed_expense_id' => 'fixed_expenses',
        ];
        foreach ($references as $field => $table) {
            if (! empty($input[$field]) && ! DB::table($table)->where('owner_id', $ownerId)->where('id', $input[$field])->exists()) {
                $this->fail($field, 'That record is not available in your workspace.');
            }
        }
        foreach (['employee_ids' => 'employees', 'allocations' => 'invoices'] as $field => $table) {
            foreach ($input[$field] ?? [] as $row) {
                $id = is_array($row) ? ($row['invoice_id'] ?? null) : $row;
                if ($id && ! DB::table($table)->where('owner_id', $ownerId)->where('id', $id)->exists()) {
                    $this->fail($field, 'A selected record is not available in your workspace.');
                }
            }
        }
        $entity = match ($action) {
            'update-customer', 'delete-customer' => 'customers',
            'update-employee', 'delete-employee' => 'employees',
            'update-gate-pass', 'delete-gate-pass' => 'gate_passes',
            'void-invoice' => 'invoices',
            'toggle-fixed', 'pay-fixed', 'defer-fixed' => 'fixed_expenses',
            'pay-salary' => 'payrolls',
            default => null,
        };
        if ($entity && ! empty($input['id']) && ! DB::table($entity)->where('owner_id', $ownerId)->where('id', $input['id'])->exists()) {
            $this->fail('id', 'That record is not available in your workspace.');
        }
        if ($action === 'pay-salary' && ! empty($input['payroll_id']) && ! DB::table('payrolls')->where('owner_id', $ownerId)->where('id', $input['payroll_id'])->exists()) {
            $this->fail('payroll_id', 'That payroll record is not available in your workspace.');
        }
        if ($action === 'manual-entry' && ! empty($input['account_id']) && ! DB::table('accounts')->where('owner_id', $ownerId)->where('id', $input['account_id'])->exists()) {
            $this->fail('account_id', 'That account is not available in your workspace.');
        }
    }

    public function post(string $action, array $input, int $userId): int
    {
        Validator::make($input, ['submission_key' => 'required|uuid'])->validate();

        $this->ownerId = $userId;
        $this->assertOwnedReferences($action, $input, $userId);

        return DB::transaction(function () use ($action, $input, $userId) {
            $inserted = DB::table('submission_keys')->insertOrIgnore([
                'key' => $input['submission_key'], 'user_id' => $userId, 'action' => $action,
                'owner_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $inserted) {
                return 0;
            }
            $id = match ($action) {
                'customers' => $this->customer($input),
                'units' => $this->unit($input),
                'invoices' => $this->invoice($input),
                'costing', 'fabric-costings' => $this->fabricCosting($input),
                'link-fabric-costing' => $this->linkFabricCosting($input),
                'receipts' => $this->receipt($input),
                'accounts' => $this->account($input),
                'account-types' => $this->accountType($input),
                'employees' => $this->employee($input),
                'attendance' => $this->attendance($input),
                'payrolls' => $this->payroll($input),
                'pay-salary' => $this->paySalary($input),
                'expenses' => $this->expense($input),
                'fixed-expenses' => $this->fixedExpense($input),
                'pay-fixed' => $this->payFixed($input),
                'defer-fixed' => $this->deferFixed($input),
                'gate-passes' => $this->gatePass($input),
                'update-gate-pass' => $this->updateGatePass($input),
                'delete-gate-pass' => $this->deleteGatePass($input),
                'void-invoice' => $this->voidInvoice($input),
                'manual-entry' => $this->manualEntry($input),
                'update-customer' => $this->updateCustomer($input),
                'delete-customer' => $this->deleteCustomer($input),
                'update-employee' => $this->updateEmployee($input),
                'delete-employee' => $this->deleteEmployee($input),
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
        $data = Validator::make($input, ['name' => 'required|string|max:120', 'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:160', 'address' => 'nullable|string|max:500', 'tax_id' => 'nullable|string|max:80',
            'opening_balance' => 'nullable|numeric|min:0|max:100000000'])->validate();
        $data['opening_balance'] = self::cents($data['opening_balance'] ?? 0);
        if (DB::table('customers')->where('owner_id', $this->ownerId)->where('phone', $data['phone'])->exists()) {
            $this->fail('phone', 'This phone number is already in use in your workspace.');
        }

        return $this->insert('customers', $data);
    }

    public function unit(array $input): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        $nameKey = mb_strtolower($name);
        $standardNames = ['piece', 'meter', 'yard', 'kg', 'dozen', 'bundle', 'roll', 'lot'];
        $data = Validator::make(['name' => $name, 'meters_per_unit' => $input['meters_per_unit'] ?? null], [
            'name' => 'required|string|max:30', 'meters_per_unit' => 'required|numeric|gt:0|max:1000000',
        ])->validate();
        if (in_array($nameKey, $standardNames, true) || DB::table('units')->where('owner_id', $this->ownerId)->where('name_key', $nameKey)->exists()) {
            $this->fail('name', 'That unit name already exists. Choose a different name.');
        }

        return $this->insert('units', ['name' => $data['name'], 'name_key' => $nameKey,
            'meters_per_unit' => $data['meters_per_unit']]);
    }

    public function invoice(array $input): int
    {
        $data = Validator::make($input, [
            'customer_id' => 'required|exists:customers,id', 'date' => 'required|date_format:Y-m-d',
            'due_date' => 'required|date_format:Y-m-d|after_or_equal:date', 'notes' => 'nullable|string|max:1000',
            'discount' => 'nullable|numeric|min:0|max:100000000', 'items' => 'required|array|min:1|max:100',
            'items.*.description' => 'required|string|max:200', 'items.*.unit' => 'required|string|max:30',
            'items.*.quantity' => 'required|numeric|decimal:0,3|min:0.001|max:1000000', 'items.*.rate' => 'required|numeric|min:0.01|max:10000000',
            'costing_ids' => 'nullable|array', 'costing_ids.*' => 'integer|distinct',
        ])->validate();
        if (DB::table('customers')->where('owner_id', $this->ownerId)->where('id', $data['customer_id'])->value('status') !== 'Active') {
            $this->fail('customer_id', 'Select an active customer.');
        }
        if (! empty($data['costing_ids'])) {
            foreach ($data['costing_ids'] as $costingId) {
                if (! DB::table('fabric_costings')->where('owner_id', $this->ownerId)->where('id', $costingId)->whereNull('invoice_id')->exists()) {
                    $this->fail('costing_ids', 'A selected costing is unavailable or already linked to an invoice.');
                }
            }
        }
        $items = array_map(function (array $row, int $index): array {
            $unit = DB::table('units')->where('owner_id', $this->ownerId)->where('name_key', mb_strtolower(trim($row['unit'])))->first();
            $multiplier = $unit ? (float) $unit->meters_per_unit : 1;
            $rate = self::cents($row['rate']);

            return [...$row, 'rate' => $rate, 'unit_multiplier' => $multiplier,
                'amount' => (int) round(round((float) $row['quantity'], 3) * $multiplier * $rate)];
        }, $data['items'], array_keys($data['items']));
        $subtotal = array_sum(array_column($items, 'amount'));
        $discount = self::cents($data['discount'] ?? 0);
        if ($discount >= $subtotal) {
            $this->fail('discount', 'Discount must be less than the subtotal.');
        }
        $id = $this->insert('invoices', ['customer_id' => $data['customer_id'], 'date' => $data['date'], 'due_date' => $data['due_date'],
            'notes' => $data['notes'] ?? null, 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $subtotal - $discount]);
        foreach ($items as $item) {
            DB::table('invoice_items')->insert(['invoice_id' => $id, 'owner_id' => $this->ownerId, ...$item]);
        }
        if (! empty($data['costing_ids'])) {
            foreach ($data['costing_ids'] as $costingId) {
                DB::table('fabric_costings')->where('owner_id', $this->ownerId)->where('id', $costingId)->update(['invoice_id' => $id, 'updated_at' => now()]);
            }
        }

        return $id;
    }

    public function fabricCosting(array $input): int
    {
        $data = Validator::make($input, [
            'name' => 'nullable|string|max:120', 'quantity' => 'required|numeric|gt:0|max:100000000',
            'read' => 'required|numeric|gt:0|max:100000', 'pick' => 'required|numeric|gt:0|max:100000',
            'warp_count' => 'required|numeric|gt:0|max:100000', 'weft_count' => 'required|numeric|gt:0|max:100000',
            'width' => 'required|numeric|gt:0|max:100000', 'yarn_warp_rate' => 'required|numeric|min:0|max:100000000',
            'yarn_weft_rate' => 'required|numeric|min:0|max:100000000', 'conversion_rate' => 'required|numeric|min:0|max:100000000',
        ])->validate();
        $result = FabricCosting::calculate($data);
        $persist = collect($result)->except(['fabric_rate_per_mtr', 'contract_value', 'conv_value', 'yarn_value', 'sales_tax_amount', 'warp_amount_per_mtr', 'weft_amount_per_mtr', 'conversion_per_mtr'])->all();
        $persist['fabric_rate_per_mtr'] = self::cents($result['fabric_rate_per_mtr']);
        $persist['contract_value'] = self::cents($result['contract_value']);
        $persist['conv_value'] = self::cents($result['conv_value']);
        $persist['yarn_value'] = self::cents($result['yarn_value']);
        $persist['sales_tax_amount'] = self::cents($result['sales_tax_amount']);
        $persist['warp_amount_per_mtr'] = self::cents($result['warp_amount_per_mtr']);
        $persist['weft_amount_per_mtr'] = self::cents($result['weft_amount_per_mtr']);
        $persist['conversion_per_mtr'] = self::cents($result['conversion_per_mtr']);

        return $this->insert('fabric_costings', $persist);
    }

    public function linkFabricCosting(array $input): int
    {
        $data = Validator::make($input, [
            'costing_id' => 'required|integer', 'invoice_id' => 'nullable|integer',
        ])->validate();
        $costing = DB::table('fabric_costings')->where('owner_id', $this->ownerId)->where('id', $data['costing_id'])->first();
        if (! $costing) {
            $this->fail('costing_id', 'That costing is not available in your workspace.');
        }
        $invoiceId = $data['invoice_id'] ?? null;
        if ($invoiceId && ! DB::table('invoices')->where('owner_id', $this->ownerId)->where('id', $invoiceId)->where('status', 'Posted')->exists()) {
            $this->fail('invoice_id', 'Select a posted invoice from your workspace.');
        }
        DB::table('fabric_costings')->where('id', $costing->id)->update(['invoice_id' => $invoiceId, 'updated_at' => now()]);

        return (int) $costing->id;
    }

    public function receipt(array $input): int
    {
        $data = Validator::make($input, ['customer_id' => 'required|exists:customers,id', 'account_id' => 'required|exists:accounts,id',
            'date' => 'required|date_format:Y-m-d', 'amount' => 'required|numeric|min:0.01|max:100000000',
            'method' => 'required|string|max:60', 'reference' => 'nullable|string|max:200',
            'allocation_mode' => 'required|in:oldest,manual', 'allocations' => 'nullable|array',
            'allocations.*.invoice_id' => 'required|integer|distinct|exists:invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0|max:100000000'])->validate();
        $account = DB::table('accounts')->where('owner_id', $this->ownerId)->where('id', $data['account_id'])->first();
        $accountType = mb_strtolower(trim((string) $account->type));
        $methods = match ($accountType) {
            'cash' => ['Cash'],
            'bank' => ['Bank transfer', 'Cheque', 'Card', 'Other'],
            default => array_values(array_unique([$account->type ?: $account->name, 'Online', 'Other'])),
        };
        if (! in_array($data['method'], $methods, true)) {
            $this->fail('method', 'Choose a payment method that matches the selected receiving account.');
        }
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
            DB::table('allocations')->insert(['receipt_id' => $id, 'owner_id' => $this->ownerId, ...$allocation]);
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
        $data = Validator::make($input, ['name' => 'required|string|max:100', 'type' => 'required|string|max:60',
            'number' => 'nullable|string|max:40', 'opening_balance' => 'required|numeric|min:0|max:100000000'])->validate();
        $typeKey = mb_strtolower(trim($data['type']));
        $type = DB::table('account_types')->where('owner_id', $this->ownerId)->where('name_key', $typeKey)->value('name');
        if (! in_array($typeKey, ['cash', 'bank'], true) && ! $type) {
            $this->fail('type', 'Choose Cash, Bank, or a saved account type.');
        }
        $data['type'] = $type ?: ucfirst($typeKey);
        $data['opening_balance'] = self::cents($data['opening_balance']);

        return $this->insert('accounts', $data);
    }

    public function accountType(array $input): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        $nameKey = mb_strtolower($name);
        $data = Validator::make(['name' => $name], ['name' => 'required|string|max:60'])->validate();
        if (in_array($nameKey, ['cash', 'bank'], true) || DB::table('account_types')->where('owner_id', $this->ownerId)->where('name_key', $nameKey)->exists()) {
            $this->fail('name', 'That account type already exists. Choose a different name.');
        }

        return $this->insert('account_types', ['name' => $data['name'], 'name_key' => $nameKey]);
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
            DB::table('attendance')->updateOrInsert(['owner_id' => $this->ownerId, 'employee_id' => $employee, 'date' => $data['date']],
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
        $input['name'] = trim((string) ($input['name'] ?? ''));
        $data = Validator::make($input, ['customer_id' => 'nullable|exists:customers,id', 'invoice_id' => 'nullable|exists:invoices,id',
            'name' => 'required|string|max:120', 'date' => 'required|date_format:Y-m-d', 'type' => 'required|in:Outward,Inward', 'party' => 'nullable|string|max:120',
            'vehicle' => 'nullable|string|max:80', 'driver' => 'nullable|string|max:120', 'description' => 'required|string|max:1000',
            'quantity' => 'required|numeric|min:0.001|max:1000000', 'unit' => 'required|string|max:30', 'purpose' => 'required|string|max:500',
            'authorised_by' => 'required|string|max:120'])->validate();
        $data['name_key'] = mb_strtolower($data['name']);
        if (DB::table('gate_passes')->where('owner_id', $this->ownerId)->where('name_key', $data['name_key'])->exists()) {
            $this->fail('name', 'This gate pass name is already in use. Enter a unique name.');
        }
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

    public function updateGatePass(array $input): int
    {
        $input['name'] = trim((string) ($input['name'] ?? ''));
        $data = Validator::make($input, ['id' => 'required|integer|exists:gate_passes,id',
            'customer_id' => 'nullable|exists:customers,id', 'invoice_id' => 'nullable|exists:invoices,id',
            'name' => 'required|string|max:120', 'date' => 'required|date_format:Y-m-d',
            'type' => 'required|in:Outward,Inward', 'party' => 'nullable|string|max:120',
            'vehicle' => 'nullable|string|max:80', 'driver' => 'nullable|string|max:120',
            'description' => 'required|string|max:1000', 'quantity' => 'required|numeric|min:0.001|max:1000000',
            'unit' => 'required|string|max:30', 'purpose' => 'required|string|max:500',
            'authorised_by' => 'required|string|max:120'])->validate();
        $data['name_key'] = mb_strtolower($data['name']);
        $existing = DB::table('gate_passes')->where('id', $data['id'])->first();
        if (DB::table('gate_passes')->where('owner_id', $this->ownerId)->where('name_key', $data['name_key'])->where('id', '!=', $data['id'])->exists()) {
            $this->fail('name', 'This gate pass name is already in use. Enter a unique name.');
        }
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
        $id = (int) $data['id'];
        unset($data['id']);
        DB::table('gate_passes')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        $this->insert('audit_logs', ['user_id' => auth()->id(), 'action' => 'update-gate-pass',
            'entity' => 'gate-passes', 'entity_id' => $id, 'details' => json_encode(['before' => $existing, 'after' => $data])]);

        return $id;
    }

    public function deleteGatePass(array $input): int
    {
        $data = Validator::make($input, ['id' => 'required|integer|exists:gate_passes,id'])->validate();
        $record = DB::table('gate_passes')->where('id', $data['id'])->first();
        DB::table('gate_passes')->where('id', $data['id'])->delete();
        $this->insert('audit_logs', ['user_id' => auth()->id(), 'action' => 'delete-gate-pass',
            'entity' => 'gate-passes', 'entity_id' => $data['id'], 'details' => json_encode($record)]);

        return (int) $data['id'];
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
        $rules = ['id' => 'required|exists:customers,id', 'status' => 'required|in:Active,Inactive'];
        if (array_key_exists('name', $input)) {
            $input['phone'] = preg_replace('/[^0-9+]/', '', $input['phone'] ?? '');
            $rules = [...$rules, 'name' => 'required|string|max:120', 'phone' => 'required|string|max:30',
                'email' => 'nullable|email|max:160', 'address' => 'nullable|string|max:500', 'tax_id' => 'nullable|string|max:80',
                'opening_balance' => 'nullable|numeric|min:0|max:100000000'];
        }
        $data = Validator::make($input, $rules)->validate();
        if (isset($data['phone']) && DB::table('customers')->where('owner_id', $this->ownerId)->where('phone', $data['phone'])->where('id', '!=', $data['id'])->exists()) {
            $this->fail('phone', 'This phone number is already in use in your workspace.');
        }
        if (array_key_exists('opening_balance', $data)) {
            $data['opening_balance'] = self::cents($data['opening_balance'] ?? 0);
        }
        $id = (int) $data['id'];
        unset($data['id']);
        DB::table('customers')->where('id', $id)->update([...$data, 'updated_at' => now()]);

        return $id;
    }

    public function deleteCustomer(array $input): int
    {
        $data = Validator::make($input, ['id' => 'required|integer|exists:customers,id'])->validate();
        $customerId = (int) $data['id'];
        if (DB::table('invoices')->where('customer_id', $customerId)->exists() || DB::table('receipts')->where('customer_id', $customerId)->exists()) {
            $this->fail('id', 'This customer has invoice or receipt history and cannot be deleted. Deactivate the customer instead.');
        }
        $customer = DB::table('customers')->where('id', $customerId)->first();
        DB::table('customers')->where('id', $customerId)->delete();
        $this->insert('audit_logs', ['user_id' => auth()->id(), 'action' => 'delete-customer', 'entity' => 'customers',
            'entity_id' => $customerId, 'details' => json_encode($customer)]);

        return $customerId;
    }

    public function updateEmployee(array $input): int
    {
        $rules = ['id' => 'required|exists:employees,id', 'status' => 'required|in:Active,Inactive'];
        if (collect(['name', 'phone', 'department', 'designation', 'joining_date', 'salary_type', 'salary'])->contains(fn ($field) => array_key_exists($field, $input))) {
            $rules = [...$rules, 'name' => 'required|string|max:120', 'phone' => 'required|string|max:30',
                'department' => 'required|string|max:80', 'designation' => 'required|string|max:80',
                'joining_date' => 'required|date_format:Y-m-d', 'salary_type' => 'required|in:Monthly,Daily',
                'salary' => 'required|numeric|min:0.01|max:10000000'];
        }
        $data = Validator::make($input, $rules)->validate();
        if (isset($data['salary'])) {
            $data['salary'] = self::cents($data['salary']);
        }
        $id = (int) $data['id'];
        unset($data['id']);
        DB::table('employees')->where('id', $id)->update([...$data, 'updated_at' => now()]);

        return $id;
    }

    public function deleteEmployee(array $input): int
    {
        $data = Validator::make($input, ['id' => 'required|integer|exists:employees,id'])->validate();
        $employeeId = (int) $data['id'];
        $hasPayroll = DB::table('payrolls')->where('employee_id', $employeeId)->exists();
        $hasAttendance = DB::table('attendance')->where('employee_id', $employeeId)->exists();
        if ($hasPayroll || $hasAttendance) {
            $this->fail('id', 'This employee has payroll or attendance history and cannot be deleted. Deactivate the employee instead.');
        }
        $employee = DB::table('employees')->where('id', $employeeId)->first();
        DB::table('employees')->where('id', $employeeId)->delete();
        $this->insert('audit_logs', ['user_id' => auth()->id(), 'action' => 'delete-employee', 'entity' => 'employees',
            'entity_id' => $employeeId, 'details' => json_encode($employee)]);

        return $employeeId;
    }

    public function toggleFixed(array $input): int
    {
        $data = Validator::make($input, ['id' => 'required|exists:fixed_expenses,id', 'active' => 'required|boolean'])->validate();
        DB::table('fixed_expenses')->where('id', $data['id'])->update(['active' => $data['active'], 'updated_at' => now()]);

        return (int) $data['id'];
    }

    public function snapshot(?int $ownerId = null): array
    {
        $data = ['production' => $ownerId === null ? app(ProductionReport::class)->records() : []];
        foreach (['customers', 'accounts', 'account_types', 'invoices', 'receipts', 'employees', 'attendance', 'payrolls', 'expenses', 'fixed_expenses', 'gate_passes', 'transactions', 'audit_logs', 'units', 'fabric_costings'] as $table) {
            $query = DB::table($table);
            if ($ownerId !== null) {
                $query->where('owner_id', $ownerId);
            }
            $data[$table] = $query->orderByDesc('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $items = DB::table('invoice_items');
        $allocations = DB::table('allocations');
        if ($ownerId !== null) {
            $items->where('owner_id', $ownerId);
            $allocations->where('owner_id', $ownerId);
        }
        $data['invoice_items'] = $items->get()->map(fn ($r) => (array) $r)->all();
        $data['allocations'] = $allocations->get()->map(fn ($r) => (array) $r)->all();
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
