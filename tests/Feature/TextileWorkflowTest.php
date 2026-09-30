<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TextileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TextileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private TextileService $service;

    private User $admin;

    private int $customer;

    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 29));
        $this->service = app(TextileService::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->customer = $this->save('customers', ['name' => 'Test Textiles', 'phone' => '+15551112222']);
        $this->account = $this->save('accounts', ['name' => 'Cash', 'type' => 'Cash', 'opening_balance' => 1000]);
    }

    private function save(string $action, array $data): int
    {
        return $this->service->post($action, ['submission_key' => (string) Str::uuid(), ...$data], $this->admin->id);
    }

    private function invoice(float $amount = 100): int
    {
        return $this->save('invoices', ['customer_id' => $this->customer, 'date' => '2026-09-29', 'due_date' => '2026-10-01',
            'items' => [['description' => 'Cotton', 'unit' => 'Meter', 'quantity' => 1, 'rate' => $amount]]]);
    }

    private function receipt(float $amount, array $extra = []): int
    {
        return $this->save('receipts', ['customer_id' => $this->customer, 'account_id' => $this->account,
            'date' => '2026-09-29', 'amount' => $amount, 'method' => 'Cash', 'allocation_mode' => 'oldest', ...$extra]);
    }

    private function employee(string $basis = 'Monthly', float $salary = 3000): int
    {
        return $this->save('employees', ['name' => 'Worker', 'phone' => '55512345', 'department' => 'Weaving',
            'designation' => 'Operator', 'joining_date' => '2026-01-01', 'salary_type' => $basis, 'salary' => $salary]);
    }

    public function test_invoice_creates_receivable_without_cash_and_uses_quantity_rate_discount(): void
    {
        $id = $this->save('invoices', ['customer_id' => $this->customer, 'date' => '2026-09-29', 'due_date' => '2026-10-01',
            'discount' => 200, 'items' => [['description' => 'Cotton', 'unit' => 'Piece', 'quantity' => 120, 'rate' => 85]]]);
        $this->assertDatabaseHas('invoices', ['id' => $id, 'subtotal' => 1020000, 'total' => 1000000]);
        $this->assertDatabaseCount('transactions', 0);
        $snapshot = $this->service->snapshot();
        $this->assertSame(1000000, $snapshot['customers'][0]['balance']);
        $this->assertSame('Unpaid', $snapshot['invoices'][0]['payment_status']);
    }

    public function test_customer_opening_balance_is_optional_and_saved_in_cents(): void
    {
        $customer = $this->save('customers', ['name' => 'Opening Balance Co', 'phone' => '5557770001', 'opening_balance' => '125.75']);
        $this->assertDatabaseHas('customers', ['id' => $customer, 'opening_balance' => 12575]);
        $this->assertSame(12575, collect($this->service->snapshot()['customers'])->firstWhere('id', $customer)['balance']);

        $withoutOpeningBalance = $this->save('customers', ['name' => 'No Balance Co', 'phone' => '5557770002']);
        $this->assertDatabaseHas('customers', ['id' => $withoutOpeningBalance, 'opening_balance' => 0]);
    }

    public function test_saved_custom_length_units_are_available_later_and_convert_invoice_totals(): void
    {
        $unit = $this->save('units', ['name' => 'Guize', 'meters_per_unit' => 4.5]);
        $this->assertDatabaseHas('units', ['id' => $unit, 'name' => 'Guize', 'name_key' => 'guize', 'meters_per_unit' => 4.5]);
        $this->assertSame('Guize', $this->service->snapshot()['units'][0]['name']);

        $invoice = $this->save('invoices', ['customer_id' => $this->customer, 'date' => '2026-09-29', 'due_date' => '2026-10-01',
            'items' => [['description' => 'Custom length fabric', 'unit' => 'Guize', 'quantity' => 3, 'rate' => 10]]]);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice, 'unit' => 'Guize', 'unit_multiplier' => 4.5, 'amount' => 13500]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice, 'total' => 13500]);

        $this->expectException(ValidationException::class);
        $this->save('units', ['name' => ' guIZE ', 'meters_per_unit' => 2]);
    }

    public function test_custom_account_types_can_be_saved_and_used_by_accounts(): void
    {
        $type = $this->save('account-types', ['name' => 'Savings']);
        $this->assertDatabaseHas('account_types', ['id' => $type, 'name' => 'Savings', 'name_key' => 'savings']);
        $account = $this->save('accounts', ['name' => 'Reserve', 'type' => 'Savings', 'opening_balance' => 25]);
        $this->assertDatabaseHas('accounts', ['id' => $account, 'type' => 'Savings', 'opening_balance' => 2500]);

        $this->expectException(ValidationException::class);
        $this->save('account-types', ['name' => ' savings ']);
    }

    public function test_customer_can_be_edited_and_deleted_until_financial_history_exists(): void
    {
        $customer = $this->save('customers', ['name' => 'Editable Customer', 'phone' => '5557770011']);
        $this->actingAs($this->admin)->post('/actions/update-customer', [
            'submission_key' => (string) Str::uuid(), 'id' => $customer, 'name' => 'Edited Customer',
            'phone' => '(555) 777-0011', 'email' => 'edited@example.com', 'address' => 'New address',
            'tax_id' => 'TAX-2', 'opening_balance' => '250.50', 'status' => 'Active',
        ])->assertRedirect();
        $this->assertDatabaseHas('customers', ['id' => $customer, 'name' => 'Edited Customer', 'phone' => '5557770011', 'opening_balance' => 25050]);

        $this->post('/actions/delete-customer', ['submission_key' => (string) Str::uuid(), 'id' => $customer])->assertRedirect();
        $this->assertDatabaseMissing('customers', ['id' => $customer]);
    }

    public function test_customer_with_invoice_history_cannot_be_deleted(): void
    {
        $invoice = $this->invoice();
        $customer = DB::table('invoices')->where('id', $invoice)->value('customer_id');

        $this->actingAs($this->admin)->post('/actions/delete-customer', ['submission_key' => (string) Str::uuid(), 'id' => $customer])
            ->assertSessionHasErrors('id');
        $this->assertDatabaseHas('customers', ['id' => $customer]);
    }

    public function test_receipt_allocates_multiple_invoices_stores_advance_and_reconciles_cash(): void
    {
        $one = $this->invoice(100);
        $two = $this->invoice(200);
        $id = $this->receipt(350);
        $this->assertDatabaseHas('allocations', ['receipt_id' => $id, 'invoice_id' => $one, 'amount' => 10000]);
        $this->assertDatabaseHas('allocations', ['receipt_id' => $id, 'invoice_id' => $two, 'amount' => 20000]);
        $this->assertDatabaseHas('receipts', ['id' => $id, 'credit' => 5000]);
        $snapshot = $this->service->snapshot();
        $this->assertSame(-5000, $snapshot['customers'][0]['balance']);
        $this->assertSame(135000, $snapshot['accounts'][0]['balance']);
        $this->assertSame('Paid', $snapshot['invoices'][0]['payment_status']);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_invalid_allocation_rolls_back_receipt_cash_and_submission_key(): void
    {
        $id = $this->invoice(100);
        $before = DB::table('submission_keys')->count();
        try {
            $this->receipt(150, ['allocation_mode' => 'manual', 'allocations' => [['invoice_id' => $id, 'amount' => 150]]]);
            $this->fail('Expected allocation validation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }
        $this->assertDatabaseCount('receipts', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('submission_keys', $before);
    }

    public function test_receipt_cannot_allocate_to_another_customers_invoice(): void
    {
        $id = $this->invoice();
        $other = $this->save('customers', ['name' => 'Another', 'phone' => '5559999']);
        $this->expectException(ValidationException::class);
        $this->receipt(50, ['customer_id' => $other, 'allocation_mode' => 'manual', 'allocations' => [['invoice_id' => $id, 'amount' => 50]]]);
    }

    public function test_idempotent_retry_does_not_duplicate_postings(): void
    {
        $this->invoice();
        $key = (string) Str::uuid();
        $input = ['submission_key' => $key, 'customer_id' => $this->customer, 'account_id' => $this->account,
            'date' => '2026-09-29', 'amount' => 60, 'method' => 'Cash', 'allocation_mode' => 'oldest'];
        $this->service->post('receipts', $input, $this->admin->id);
        $this->assertSame(0, $this->service->post('receipts', $input, $this->admin->id));
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame('Partially Paid', $this->service->snapshot()['invoices'][0]['payment_status']);
    }

    public function test_void_preserves_invoice_and_turns_receipt_into_credit_without_reversing_cash(): void
    {
        $invoice = $this->invoice();
        $receipt = $this->receipt(40);
        $this->save('void-invoice', ['invoice_id' => $invoice, 'reason' => 'Customer cancelled order']);
        $this->assertDatabaseHas('invoices', ['id' => $invoice, 'status' => 'Voided', 'void_reason' => 'Customer cancelled order']);
        $this->assertDatabaseHas('receipts', ['id' => $receipt, 'credit' => 4000]);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(-4000, $this->service->snapshot()['customers'][0]['balance']);
    }

    public function test_expense_and_cash_book_post_together(): void
    {
        $id = $this->save('expenses', ['date' => '2026-09-29', 'account_id' => $this->account,
            'category' => 'Repair', 'description' => 'Machine repair', 'amount' => 25.75]);
        $this->assertDatabaseHas('transactions', ['source_type' => 'expenses', 'source_id' => $id, 'type' => 'out', 'amount' => 2575]);
        $this->assertSame(97425, $this->service->snapshot()['accounts'][0]['balance']);
    }

    public function test_recurring_setup_has_no_cash_effect_until_paid_and_same_due_cannot_be_paid_twice(): void
    {
        $id = $this->save('fixed-expenses', ['name' => 'Rent', 'category' => 'Rent', 'amount' => 400,
            'frequency' => 'Monthly', 'next_due' => '2026-09-30', 'account_id' => $this->account]);
        $this->assertDatabaseCount('transactions', 0);
        $payment = ['fixed_expense_id' => $id, 'expected_due' => '2026-09-30', 'date' => '2026-09-29', 'amount' => 425, 'account_id' => $this->account];
        $this->save('pay-fixed', $payment);
        $this->assertDatabaseHas('fixed_expenses', ['id' => $id, 'next_due' => '2026-10-30']);
        $this->assertDatabaseHas('expenses', ['fixed_expense_id' => $id, 'amount' => 42500]);
        try {
            $this->save('pay-fixed', $payment);
            $this->fail('Duplicate period must fail.');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_monthly_payroll_uses_attendance_and_only_payment_moves_cash(): void
    {
        $employee = $this->employee();
        $this->save('attendance', ['employee_ids' => [$employee], 'date' => '2026-09-01', 'status' => 'Absent']);
        $this->save('attendance', ['employee_ids' => [$employee], 'date' => '2026-09-02', 'status' => 'Half Day', 'overtime' => 2]);
        $id = $this->save('payrolls', ['employee_id' => $employee, 'period' => '2026-09', 'allowances' => 100, 'deductions' => 50, 'overtime_rate' => 10]);
        $this->assertDatabaseHas('payrolls', ['id' => $id, 'earned' => 285000, 'net' => 292000]);
        $this->assertDatabaseCount('transactions', 0);
        $this->save('pay-salary', ['payroll_id' => $id, 'date' => '2026-09-29', 'account_id' => $this->account, 'amount' => 1000]);
        $this->assertDatabaseHas('payrolls', ['id' => $id, 'paid' => 100000]);
        $this->assertDatabaseCount('transactions', 1);
        $this->expectException(ValidationException::class);
        $this->save('attendance', ['employee_ids' => [$employee], 'date' => '2026-09-01', 'status' => 'Present']);
    }

    public function test_daily_payroll_counts_present_and_half_days_only(): void
    {
        $employee = $this->employee('Daily', 100);
        foreach (['Present', 'Half Day', 'Absent', 'Off'] as $i => $status) {
            $this->save('attendance', ['employee_ids' => [$employee], 'date' => '2026-09-0'.($i + 1), 'status' => $status]);
        }
        $id = $this->save('payrolls', ['employee_id' => $employee, 'period' => '2026-09']);
        $this->assertDatabaseHas('payrolls', ['id' => $id, 'net' => 15000]);
    }

    public function test_gate_pass_infers_customer_from_invoice(): void
    {
        $invoice = $this->invoice();
        $id = $this->save('gate-passes', ['name' => 'Cotton delivery September 29', 'invoice_id' => $invoice, 'date' => '2026-09-29', 'type' => 'Outward',
            'description' => 'Cotton', 'quantity' => 10, 'unit' => 'Meter', 'purpose' => 'Delivery', 'authorised_by' => 'Supervisor']);
        $this->assertDatabaseHas('gate_passes', ['id' => $id, 'name' => 'Cotton delivery September 29', 'invoice_id' => $invoice, 'customer_id' => $this->customer]);
    }

    public function test_gate_pass_names_are_trimmed_and_unique_without_case_sensitivity(): void
    {
        $base = ['date' => '2026-09-29', 'type' => 'Outward', 'description' => 'Cotton', 'quantity' => 10,
            'unit' => 'Meter', 'purpose' => 'Delivery', 'authorised_by' => 'Supervisor'];
        $id = $this->save('gate-passes', ['name' => '  Lot A dispatch  ', ...$base]);
        $this->assertDatabaseHas('gate_passes', ['id' => $id, 'name' => 'Lot A dispatch', 'name_key' => 'lot a dispatch']);

        try {
            $this->save('gate-passes', ['name' => 'lot a DISPATCH', ...$base]);
            $this->fail('A duplicate gate pass name must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        $this->assertDatabaseCount('gate_passes', 1);
    }

    public function test_admin_can_edit_and_delete_gate_passes_and_actions_are_audited(): void
    {
        $base = ['name' => 'Morning cotton dispatch', 'date' => '2026-09-29', 'type' => 'Outward',
            'description' => 'Cotton fabric', 'quantity' => 10, 'unit' => 'Meter', 'purpose' => 'Delivery',
            'authorised_by' => 'Supervisor'];
        $id = $this->save('gate-passes', $base);

        $this->actingAs($this->admin)->post('/actions/update-gate-pass', [
            'submission_key' => (string) Str::uuid(), 'id' => $id, ...$base, 'name' => 'Afternoon cotton dispatch',
        ])->assertRedirect();
        $this->assertDatabaseHas('gate_passes', ['id' => $id, 'name' => 'Afternoon cotton dispatch']);

        $this->post('/actions/delete-gate-pass', ['submission_key' => (string) Str::uuid(), 'id' => $id])->assertRedirect();
        $this->assertDatabaseMissing('gate_passes', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'gate-passes', 'entity_id' => $id, 'action' => 'delete-gate-pass']);
    }

    public function test_staff_cannot_edit_or_delete_gate_passes(): void
    {
        $id = $this->save('gate-passes', ['name' => 'Staff test pass', 'date' => '2026-09-29', 'type' => 'Outward',
            'description' => 'Cotton fabric', 'quantity' => 10, 'unit' => 'Meter', 'purpose' => 'Delivery',
            'authorised_by' => 'Supervisor']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->post('/actions/delete-gate-pass', ['submission_key' => (string) Str::uuid(), 'id' => $id])->assertForbidden();
        $this->assertDatabaseHas('gate_passes', ['id' => $id, 'name' => 'Staff test pass']);
    }

    public function test_employee_can_be_updated_and_deleted_only_without_history(): void
    {
        $employee = $this->employee();
        $this->actingAs($this->admin)->post('/actions/update-employee', [
            'submission_key' => (string) Str::uuid(), 'id' => $employee, 'name' => 'Updated worker',
            'phone' => '55512345', 'department' => 'Finishing', 'designation' => 'Lead operator',
            'joining_date' => '2026-01-01', 'salary_type' => 'Monthly', 'salary' => 3500, 'status' => 'Active',
        ])->assertRedirect();
        $this->assertDatabaseHas('employees', ['id' => $employee, 'name' => 'Updated worker', 'department' => 'Finishing', 'salary' => 350000]);

        $this->post('/actions/delete-employee', ['submission_key' => (string) Str::uuid(), 'id' => $employee])->assertRedirect();
        $this->assertDatabaseMissing('employees', ['id' => $employee]);
    }

    public function test_employee_with_payroll_history_cannot_be_deleted(): void
    {
        $employee = $this->employee();
        $this->save('attendance', ['employee_ids' => [$employee], 'date' => '2026-09-01', 'status' => 'Present']);
        $this->save('payrolls', ['employee_id' => $employee, 'period' => '2026-09']);

        $this->actingAs($this->admin)->post('/actions/delete-employee', ['submission_key' => (string) Str::uuid(), 'id' => $employee])
            ->assertSessionHasErrors('id');
        $this->assertDatabaseHas('employees', ['id' => $employee, 'status' => 'Active']);
    }

    public function test_duplicate_normalised_phone_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->save('customers', ['name' => 'Duplicate', 'phone' => '+1 (555) 111-2222']);
    }

    public function test_guests_and_staff_cannot_post_privileged_actions(): void
    {
        $this->get('/')->assertRedirect('/login');
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->post('/actions/manual-entry', [])->assertForbidden();
        $this->actingAs($staff)->post('/actions/payrolls', [])->assertForbidden();
        $this->actingAs($staff)->post('/actions/expenses', ['date' => '2026-09-28'])->assertForbidden();
    }

    public function test_all_workspace_pages_render_and_record_links_resolve(): void
    {
        $this->withoutVite();
        $this->actingAs($this->admin);
        foreach (['/', '/customers', '/invoices', '/receipts', '/cashbook', '/employees', '/attendance', '/payrolls', '/expenses', '/fixed-expenses', '/gate-passes', '/reports', '/ledgers', '/settings', '/customers/'.$this->customer, '/cashbook/'.$this->account] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Workspace')->has('data.customers'));
        }
        $this->get('/customers/99999')->assertNotFound();
        $this->get('/not-a-module')->assertNotFound();
    }

    public function test_production_report_reads_existing_csv_without_writing_production_records(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('production.csv', "date,reference,item,quantity,unit,source_url\n2026-09-12,PROD-17,Cotton twill,240.5,Meter,https://example.com/production/17\ninvalid,bad,Missing fields,n/a,Piece,javascript:alert(1)\n");
        $snapshot = $this->service->snapshot();
        $this->assertCount(1, $snapshot['production']);
        $this->assertSame(240.5, $snapshot['production'][0]['quantity']);
        $this->assertSame('https://example.com/production/17', $snapshot['production'][0]['source_url']);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_login_regenerates_session_and_logout_protects_workspace(): void
    {
        $user = User::factory()->create(['password' => 'SecurePassword123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'SecurePassword123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
