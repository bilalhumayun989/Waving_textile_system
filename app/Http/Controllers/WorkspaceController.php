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
        abort_unless(in_array($page, ['dashboard', 'customers', 'invoices', 'receipts', 'cashbook', 'employees', 'attendance', 'payrolls', 'expenses', 'fixed-expenses', 'gate-passes', 'reports', 'ledgers', 'settings']), 404);
        $tables = ['fixed-expenses' => 'fixed_expenses', 'gate-passes' => 'gate_passes', 'cashbook' => 'accounts'];
        $data = $service->snapshot();
        if ($id) {
            abort_unless(collect($data[$tables[$page] ?? $page] ?? [])->contains('id', (int) $id), 404);
        }

        return Inertia::render('Workspace', ['page' => $page, 'recordId' => $id ? (int) $id : null,
            'data' => $data, 'today' => today()->toDateString(), 'demo' => config('app.demo', false)]);
    }

    public function store(Request $request, TextileService $service, string $action): RedirectResponse
    {
        $adminActions = ['payrolls', 'pay-salary', 'void-invoice', 'manual-entry', 'accounts', 'update-customer', 'update-employee', 'toggle-fixed'];
        if (in_array($action, $adminActions)) {
            abort_unless($request->user()->role === 'admin', 403);
        }
        if ($request->filled('date') && $request->date < today()->toDateString()) {
            abort_unless($request->user()->role === 'admin', 403, 'Backdated entries require an administrator.');
        }
        $service->post($action, $request->except('_fixed_id'), $request->user()->id);

        return back()->with('success', 'Saved successfully. Connected records are up to date.');
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
        $request->user()->forceFill(['name' => $data['name'], 'password' => Hash::make($data['password'])])->save();

        return back()->with('success', 'Profile and password updated.');
    }
}
