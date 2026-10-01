<?php

namespace Tests\Feature;

use App\Models\User;
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
