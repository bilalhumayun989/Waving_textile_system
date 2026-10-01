<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
