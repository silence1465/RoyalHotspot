<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index()
    {
        return response()->json([
            'admins' => User::with('routers:id,name,location')->latest()->get(),
            'routers' => Router::orderBy('name')->get(['id', 'name', 'location']),
            'available_permissions' => AdminPermissions::ALL,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateAdmin($request);
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role' => $data['role'],
                'permissions' => $data['role'] === 'super_admin' ? [] : $data['permissions'],
                'status' => $data['status'],
            ]);
            $user->routers()->sync($data['role'] === 'super_admin' ? [] : $data['router_ids']);

            return $user;
        });

        return response()->json($user->load('routers:id,name,location'), 201);
    }

    public function update(Request $request, User $admin)
    {
        $data = $this->validateAdmin($request, $admin);
        DB::transaction(function () use ($admin, $data) {
            $values = collect($data)->only(['name', 'email', 'phone', 'role', 'status'])->all();
            $values['permissions'] = $data['role'] === 'super_admin' ? [] : $data['permissions'];
            if (! empty($data['password'])) {
                $values['password'] = $data['password'];
            }
            $admin->update($values);
            $admin->routers()->sync($data['role'] === 'super_admin' ? [] : $data['router_ids']);
        });

        return response()->json($admin->fresh()->load('routers:id,name,location'));
    }

    private function validateAdmin(Request $request, ?User $admin = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($admin?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => [$admin ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['super_admin', 'admin', 'support'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'router_ids' => ['array', 'required_unless:role,super_admin', 'min:1'],
            'router_ids.*' => ['integer', 'distinct', 'exists:routers,id'],
            'permissions' => ['array', 'required_unless:role,super_admin'],
            'permissions.*' => ['string', Rule::in(AdminPermissions::ALL)],
        ]);
    }
}
