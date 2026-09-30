<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\TextileService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }
        if (DB::table('customers')->exists()) {
            return;
        }
        $user = User::firstOrCreate(['email' => 'admin@threadline.test'], ['name' => 'Alex Morgan', 'password' => Hash::make('Threadline@2026')]);
        $user->forceFill(['role' => 'admin'])->save();
        $service = app(TextileService::class);
        $post = fn (string $action, array $data) => $service->post($action, ['submission_key' => (string) Str::uuid(), ...$data], $user->id);
        DB::transaction(function () use ($post) {
            $cash = $post('accounts', ['name' => 'Petty Cash', 'type' => 'Cash', 'opening_balance' => 25000]);
            $bank = $post('accounts', ['name' => 'Business Account', 'type' => 'Bank', 'number' => '???? 4821', 'opening_balance' => 185000]);
            $names = ['Loom & Co.', 'Atlas Textiles', 'Cotton Collective', 'The Fabric House', 'Weave Studio', 'Northstar Apparel', 'Urban Threads', 'Heritage Mills'];
            $customers = [];
            foreach ($names as $i => $name) {
                $customers[] = $post('customers', ['name' => $name, 'phone' => '+15550100'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'email' => strtolower(str_replace([' ', '&', '.'], '', $name)).'@example.com', 'address' => ($i + 12).' Textile Avenue, Industrial District']);
            }
            $fabrics = ['Premium cotton poplin', 'Linen blend ? Natural', 'Indigo denim fabric', 'Organic cotton twill', 'Woven viscose ? Ivory', 'Cotton jersey knit'];
            for ($i = 0; $i < 28; $i++) {
                $date = today()->subDays(29 - $i)->toDateString();
                $qty = 80 + (($i * 37) % 240);
                $rate = 25 + (($i * 13) % 75);
                $invoice = $post('invoices', ['customer_id' => $customers[$i % 8], 'date' => $date,
                    'due_date' => today()->subDays(29 - $i)->addDays(14)->toDateString(), 'discount' => 0,
                    'notes' => 'Demo order ? Quality checked before dispatch.',
                    'items' => [['description' => $fabrics[$i % 6], 'quantity' => $qty, 'unit' => $i % 3 === 0 ? 'Roll' : 'Meter', 'rate' => $rate]]]);
                if ($i % 4 !== 0) {
                    $post('receipts', ['customer_id' => $customers[$i % 8], 'account_id' => $i % 3 === 0 ? $cash : $bank,
                        'date' => $date, 'amount' => $qty * $rate * ($i % 3 === 0 ? 0.5 : 1), 'method' => 'Bank transfer',
                        'reference' => 'DEMO-TXN-'.($i + 1), 'allocation_mode' => 'manual',
                        'allocations' => [['invoice_id' => $invoice, 'amount' => $qty * $rate * ($i % 3 === 0 ? 0.5 : 1)]]]);
                }
            }
            foreach ([['Sarah Wilson', 'Operations', 'Floor supervisor', 3200], ['James Carter', 'Warehouse', 'Inventory associate', 2400], ['Olivia Chen', 'Finance', 'Accountant', 3600], ['Daniel Reed', 'Quality', 'Quality inspector', 2800], ['Maya Patel', 'Operations', 'Machine operator', 2600], ['Noah Brooks', 'Logistics', 'Dispatch lead', 2900]] as $i => $row) {
                $employee = $post('employees', ['name' => $row[0], 'phone' => '+15550200'.($i + 100), 'department' => $row[1],
                    'designation' => $row[2], 'joining_date' => today()->subMonths(8)->toDateString(), 'salary_type' => 'Monthly', 'salary' => $row[3]]);
                for ($day = 1; $day <= today()->day; $day++) {
                    $post('attendance', ['employee_ids' => [$employee], 'date' => today()->startOfMonth()->addDays($day - 1)->toDateString(),
                        'status' => $day % 7 === 0 ? 'Off' : ($day === $i + 4 ? 'Absent' : 'Present'), 'check_in' => '09:00', 'check_out' => '17:00', 'overtime' => $day % 8 === 0 ? 2 : 0]);
                }
                $post('payrolls', ['employee_id' => $employee, 'period' => today()->format('Y-m'), 'allowances' => 100, 'deductions' => 0, 'overtime_rate' => 15]);
            }
            foreach ([['Factory rent', 'Rent', 4800, 2], ['Electricity', 'Utilities', 1250, 5], ['Internet & connectivity', 'Utilities', 120, 8], ['Equipment maintenance', 'Maintenance', 600, 12]] as $row) {
                $post('fixed-expenses', ['name' => $row[0], 'category' => $row[1], 'amount' => $row[2], 'next_due' => today()->addDays($row[3])->toDateString(), 'frequency' => 'Monthly', 'account_id' => $bank]);
            }
            foreach ([['Transport', 'Fabric delivery to Atlas', 450], ['Repair', 'Loom belt replacement', 820], ['Labour', 'Weekend loading crew', 360], ['Supplies', 'Packaging and labels', 240], ['Transport', 'Local freight service', 580], ['Utilities', 'Workshop water supply', 130]] as $i => $row) {
                $post('expenses', ['date' => today()->subDays($i + 1)->toDateString(), 'category' => $row[0], 'description' => $row[1], 'amount' => $row[2], 'account_id' => $cash]);
            }
            for ($i = 0; $i < 5; $i++) {
                $post('gate-passes', ['invoice_id' => $i + 1, 'date' => today()->subDays($i)->toDateString(), 'type' => 'Outward',
                    'vehicle' => 'TRK-'.(402 + $i), 'driver' => 'Demo driver '.($i + 1), 'description' => $fabrics[$i],
                    'quantity' => 80 + $i * 37, 'unit' => 'Meter', 'purpose' => 'Customer delivery', 'authorised_by' => 'Alex Morgan']);
            }
        });
    }
}
