<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Router;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminAuthController extends Controller
{
    public function login(Request $request, TotpService $totp)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'two_factor_code' => ['nullable', 'digits:6'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid input.', 'errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        // Deliberately generic error message — don't reveal whether the
        // email exists (avoids account enumeration).
        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'These credentials do not match our records.'], 401);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'This account has been deactivated. Contact a super admin.'], 403);
        }

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            if (! $request->filled('two_factor_code') || ! $totp->verify($user->two_factor_secret, $request->input('two_factor_code'))) {
                return response()->json(['message' => 'Enter the code from your authenticator app.', 'requires_two_factor' => true], 422);
            }
        }

        // 'admin' ability is what routes/api.php actually gates on — see
        // bootstrap/app.php for why the guard name alone doesn't separate
        // admin/customer tokens.
        $token = $user->createToken('admin-token', ['admin'])->plainTextToken;

        ActivityLog::record('admin.login', "Admin {$user->email} logged in.", ['user_id' => $user->id]);

        return response()->json([
            'token' => $token,
            'user' => $this->adminPayload($user),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        ActivityLog::record('admin.logout', "Admin {$user->email} logged out.", ['user_id' => $user->id]);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return response()->json($this->adminPayload($request->user()));
    }

    private function adminPayload(User $user): array
    {
        return array_merge($user->toArray(), [
            'permissions' => $user->effectivePermissions(),
            'routers' => $user->isSuperAdmin()
                ? Router::orderBy('name')->get(['id', 'name', 'location'])
                : $user->routers()->orderBy('name')->get(['routers.id', 'name', 'location']),
            'can_select_all_routers' => true,
        ]);
    }
}
