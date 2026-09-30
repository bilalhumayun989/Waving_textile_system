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
        $id = $this->save('gate-passes', ['invoice_id' => $invoice, 'date' => '2026-09-29', 'type' => 'Outward',
            'description' => 'Cotton', 'quantity' => 10, 'unit' => 'Meter', 'purpose' => 'Delivery', 'authorised_by' => 'Supervisor']);
        $this->assertDatabaseHas('gate_passes', ['id' => $id, 'invoice_id' => $invoice, 'customer_id' => $this->customer]);
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
