<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminManagementController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return Inertia::render('Workspace', [
            'page' => 'admin-management', 'recordId' => null,
            'data' => ['admins' => User::where('role', 'admin')->orderBy('name')->get()
                ->reject(fn (User $user): bool => $user->isSuperAdmin())
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                    'modules' => $user->modules ?? array_keys(config('workspace.modules'))])->values(),
                'module_options' => config('workspace.modules')],
            'today' => today()->toDateString(), 'demo' => config('app.demo', false),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $validModules = array_keys(config('workspace.modules'));
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:160|unique:users,email',
            'password' => 'required|string|min:12|max:200',
            'modules' => 'required|array|min:1',
            'modules.*' => ['required', 'string', 'distinct', Rule::in($validModules)],
        ]);
        $reservedNames = array_map(fn (string $name): string => mb_strtolower(trim($name)), config('workspace.super_admin_names', []));
        abort_if(in_array(mb_strtolower(trim($data['name'])), $reservedNames, true), 422, 'That name is reserved for a super administrator.');

        $admin = new User;
        $admin->forceFill(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']),
            'role' => 'admin', 'modules' => array_values(array_unique($data['modules']))])->save();
        $this->audit($request, 'create-admin', $admin->id, ['name' => $admin->name, 'email' => $admin->email, 'modules' => $admin->modules]);

        return back()->with('success', 'Admin created with the selected module access.');
    }

    public function updateModules(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        abort_unless($user->role === 'admin' && ! $user->isSuperAdmin(), 404);
        $validModules = array_keys(config('workspace.modules'));
        $data = $request->validate([
            'modules' => 'required|array|min:1',
            'modules.*' => ['required', 'string', 'distinct', Rule::in($validModules)],
        ]);
        $user->forceFill(['modules' => array_values(array_unique($data['modules']))])->save();
        $this->audit($request, 'update-admin-modules', $user->id, ['modules' => $user->modules]);

        return back()->with('success', 'Module access updated.');
    }

    private function audit(Request $request, string $action, int $userId, array $details): void
    {
        DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => $action,
            'entity' => 'users', 'entity_id' => $userId, 'details' => json_encode($details),
            'created_at' => now(), 'updated_at' => now()]);
    }
}
