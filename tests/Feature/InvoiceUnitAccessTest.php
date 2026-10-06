<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class InvoiceUnitAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_access_allows_creating_a_reusable_unit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/actions/units', [
            'submission_key' => (string) Str::uuid(), 'name' => 'Guize', 'meters_per_unit' => 4.5,
        ])->assertRedirect();

        $this->assertDatabaseHas('units', ['owner_id' => $admin->id, 'name' => 'Guize', 'meters_per_unit' => 4.5]);
    }

    public function test_super_admin_role_can_log_in_and_open_admin_management(): void
    {
        User::factory()->create([
            'name' => 'Bilal Humayun', 'email' => 'bilal.humayun@gmail.com', 'password' => '12345678', 'role' => 'super_admin',
        ]);

        $this->post('/login', ['email' => 'bilal.humayun@gmail.com', 'password' => '12345678'])->assertRedirect('/');
        $this->get('/admin-management')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('page', 'admin-management'));

        $this->post('/admin-management/users', [
            'name' => 'New Workspace Admin', 'email' => 'new-admin@example.com', 'password' => 'SecurePassword123',
            'modules' => ['dashboard', 'invoices'],
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['name' => 'New Workspace Admin', 'email' => 'new-admin@example.com', 'role' => 'admin']);
        $this->get('/admin-management')->assertInertia(fn (AssertableInertia $page) => $page->where('data.admins.0.email', 'new-admin@example.com'));
    }

    public function test_demo_seeder_uses_a_payment_method_matching_each_receiving_account(): void
    {
        $this->seed(DatabaseSeeder::class);

        $mismatchedReceipts = DB::table('receipts')
            ->join('accounts', 'accounts.id', '=', 'receipts.account_id')
            ->whereRaw("(lower(accounts.type) = 'cash' and receipts.method <> 'Cash') or (lower(accounts.type) = 'bank' and receipts.method not in ('Bank transfer', 'Cheque', 'Card', 'Other'))")
            ->count();

        $this->assertSame(0, $mismatchedReceipts);
    }

    public function test_enabled_costing_action_can_be_submitted_from_the_costing_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'modules' => ['dashboard', 'costing']]);

        $this->actingAs($admin)->post('/actions/fabric-costings', [
            'submission_key' => (string) Str::uuid(), 'name' => 'Plain cotton', 'quantity' => 1000,
            'read' => 60, 'pick' => 40, 'warp_count' => 40, 'weft_count' => 40, 'width' => 44,
            'yarn_warp_rate' => 250, 'yarn_weft_rate' => 240, 'conversion_rate' => 0.02,
        ])->assertRedirect();

        $this->assertDatabaseHas('fabric_costings', ['owner_id' => $admin->id, 'name' => 'Plain cotton']);
    }

    public function test_costing_contract_details_are_saved_with_the_calculation(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'modules' => ['dashboard', 'costing']]);

        $this->actingAs($admin)->post('/actions/fabric-costings', [
            'submission_key' => (string) Str::uuid(), 'name' => 'Plain cotton', 'quantity' => 1000,
            'read' => 60, 'pick' => 40, 'warp_count' => 40, 'weft_count' => 40, 'width' => 44,
            'yarn_warp_rate' => 250, 'yarn_weft_rate' => 240, 'conversion_rate' => 0.02,
            'contract_date' => '2026-10-01', 'contract_no' => 'GC-1001', 'party_contract_no' => 'BUY-44',
            'delivery_date' => '2026-12-01', 'closed' => true, 'contract_type' => 'Grey Sale',
            'loom_type' => 'Shuttleless', 'buyer_code' => 'B-001', 'buyer_name' => 'Example Buyer',
            'buyer_gst' => 'GST-001', 'broker_code' => 'BR-001', 'broker_name' => 'Example Broker',
            'broker_commission_per_meter' => 0.5, 'quality_code' => 'Q-01', 'contract_quality' => 'Cotton plain',
            'contract_quality_width' => 44, 'panna' => 1, 'kp_percentage' => 2, 'kp_days' => 10,
            'pp_percentage' => 1, 'pp_days' => 20, 'delivery_instructions' => 'Deliver to warehouse',
        ])->assertRedirect();

        $this->assertDatabaseHas('fabric_costings', [
            'owner_id' => $admin->id, 'contract_no' => 'GC-1001', 'buyer_name' => 'Example Buyer',
            'contract_quality' => 'Cotton plain', 'closed' => true, 'delivery_instructions' => 'Deliver to warehouse',
            'kp_percentage' => 2, 'kp_days' => 10, 'pp_percentage' => 1, 'pp_days' => 20,
            'broker_commission_per_meter' => 0.5, 'contract_quality_width' => 44, 'panna' => 1,
        ]);
        $costing = DB::table('fabric_costings')->where('owner_id', $admin->id)->first();
        $this->assertSame(1000, (int) $costing->quantity);
        $this->assertSame(1, (int) $costing->closed);
        $this->assertSame('2026-10-01', $costing->contract_date);
        $this->assertGreaterThan(0, (int) $costing->fabric_rate_per_mtr);
        $this->assertSame(3777400, (int) $costing->contract_value);
        $this->assertSame(642158, (int) $costing->sales_tax_amount);
        $this->assertSame(3697400, (int) $costing->yarn_value);
        $this->assertSame(3777, (int) $costing->fabric_rate_per_mtr);
    }

    public function test_module_create_page_renders_the_standalone_form_for_an_enabled_module(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'modules' => ['dashboard', 'customers']]);

        $this->actingAs($admin)->get('/customers/create')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('page', 'customers')
                ->where('createAction', 'customers'));

        $this->get('/invoices/create')->assertForbidden();
    }

    public function test_saved_costing_name_route_opens_its_calculation_details(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'modules' => ['dashboard', 'costing']]);

        $this->actingAs($admin)->post('/actions/fabric-costings', [
            'submission_key' => (string) Str::uuid(), 'name' => 'Plain cotton', 'quantity' => 1000,
            'read' => 60, 'pick' => 40, 'warp_count' => 40, 'weft_count' => 40, 'width' => 44,
            'yarn_warp_rate' => 250, 'yarn_weft_rate' => 240, 'conversion_rate' => 0.02,
        ])->assertRedirect();

        $costingId = (int) DB::table('fabric_costings')->where('owner_id', $admin->id)->value('id');

        $this->actingAs($admin)->get('/costing/'.$costingId)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('page', 'costing')
                ->where('recordId', $costingId)
                ->where('data.fabric_costings.0.name', 'Plain cotton'));
    }

    public function test_disabled_module_access_returns_forbidden_with_a_readable_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'modules' => ['dashboard']]);

        $this->actingAs($admin)->post('/actions/units', [])->assertForbidden()
            ->assertSee('You are not allowed to do that action.');
    }

    public function test_invoice_page_does_not_include_costings_when_costing_access_is_off(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'modules' => ['dashboard', 'invoices']]);

        $this->actingAs($admin)->get('/invoices')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('page', 'invoices')->has('data.fabric_costings', 0));
    }
}
