<?php

namespace App\Http\Controllers;

use App\Services\TextileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request, TextileService $service, string $page = 'dashboard', ?string $id = null): Response
    {
        if ($page === 'dashboard' && ! $request->user()->canAccessModule('dashboard')) {
            return redirect('/'.($request->user()->accessibleModules()[0] ?? 'admin-management'));
        }
        abort_unless(in_array($page, ['dashboard', 'customers', 'invoices', 'costing', 'receipts', 'cashbook', 'employees', 'attendance', 'payrolls', 'expenses', 'fixed-expenses', 'gate-passes', 'reports', 'ledgers', 'settings']), 404);
        abort_unless($request->user()->canAccessModule($page), 403, 'Your admin has not enabled this module.');
        $tables = ['fixed-expenses' => 'fixed_expenses', 'gate-passes' => 'gate_passes', 'cashbook' => 'accounts'];
        $data = $service->snapshot($request->user()->id);
        $data = $this->limitModuleData($data, $request->user()->accessibleModules());
        if ($id) {
            abort_unless(collect($data[$tables[$page] ?? $page] ?? [])->contains('id', (int) $id), 404);
        }

        return Inertia::render('Workspace', ['page' => $page, 'recordId' => $id ? (int) $id : null,
            'data' => $data, 'today' => today()->toDateString(), 'demo' => config('app.demo', false)]);
    }

    public function store(Request $request, TextileService $service, string $action): RedirectResponse
    {
        $module = $this->actionModule($action);
        abort_unless($module && $request->user()->canAccessModule($module), 403, 'Your admin has not enabled this module.');
        $adminActions = ['payrolls', 'pay-salary', 'void-invoice', 'manual-entry', 'accounts', 'account-types', 'update-customer', 'delete-customer', 'update-employee', 'delete-employee', 'toggle-fixed', 'update-gate-pass', 'delete-gate-pass'];
        if (in_array($action, $adminActions)) {
            abort_unless($request->user()->isAdmin(), 403);
        }
        if ($request->filled('date') && $request->date < today()->toDateString() && $action !== 'update-gate-pass') {
            abort_unless($request->user()->isAdmin(), 403, 'Backdated entries require an administrator.');
        }
        $service->post($action, $request->except('_fixed_id'), $request->user()->id);

        return back()->with('success', 'Saved successfully. Connected records are up to date.');
    }

    private function actionModule(string $action): ?string
    {
        return match ($action) {
            'customers', 'update-customer', 'delete-customer' => 'customers',
            'invoices', 'void-invoice' => 'invoices',
            'fabric-costings' => 'costing',
            'receipts' => 'receipts',
            'accounts', 'account-types', 'manual-entry' => 'cashbook',
            'employees', 'update-employee', 'delete-employee' => 'employees',
            'attendance' => 'attendance',
            'payrolls' => 'payrolls',
            'pay-salary' => 'cashbook',
            'expenses' => 'expenses',
            'fixed-expenses', 'pay-fixed', 'defer-fixed', 'toggle-fixed' => 'fixed-expenses',
            'gate-passes', 'update-gate-pass', 'delete-gate-pass' => 'gate-passes',
            default => null,
        };
    }

    private function limitModuleData(array $data, array $modules): array
    {
        $has = fn (string ...$keys): bool => count(array_intersect($keys, $modules)) > 0;
        foreach ([
            'customers' => $has('customers', 'invoices', 'receipts', 'gate-passes', 'ledgers'),
            'invoices' => $has('invoices', 'receipts', 'gate-passes', 'ledgers'),
            'fabric_costings' => $has('costing', 'invoices'),
            'invoice_items' => $has('invoices', 'gate-passes', 'ledgers'),
            'receipts' => $has('receipts', 'ledgers'),
            'allocations' => $has('invoices', 'receipts', 'ledgers'),
            'accounts' => $has('cashbook', 'receipts', 'expenses', 'payrolls'),
            'transactions' => $has('cashbook'),
            'employees' => $has('employees', 'attendance', 'payrolls'),
            'attendance' => $has('attendance', 'payrolls'),
            'payrolls' => $has('payrolls'),
            'expenses' => $has('expenses', 'fixed-expenses'),
            'fixed_expenses' => $has('fixed-expenses'),
            'gate_passes' => $has('gate-passes'),
            'audit_logs' => $has('settings'),
        ] as $key => $visible) {
            if (! $visible) {
                $data[$key] = [];
            }
        }
        if (! in_array('reports', $modules, true)) {
            $data['production'] = [];
        }

        return $data;
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'The email or password is incorrect.']);
        }
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function profile(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'current_password' => 'required|current_password',
            'password' => 'required|string|min:12|confirmed']);
        if ($request->user()->isSuperAdmin()) {
            $configuredNames = array_map(fn (string $name): string => mb_strtolower(trim($name)), config('workspace.super_admin_names', []));
            abort_unless(in_array(mb_strtolower(trim($data['name'])), $configuredNames, true), 422, 'Super administrator names must remain in the SUPER_ADMIN_NAMES setting.');
        }
        $request->user()->forceFill(['name' => $data['name'], 'password' => Hash::make($data['password'])])->save();

        return back()->with('success', 'Profile and password updated.');
    }
}
