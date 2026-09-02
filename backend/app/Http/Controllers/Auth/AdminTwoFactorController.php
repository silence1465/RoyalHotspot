<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminTwoFactorController extends Controller
{
    public function setup(Request $request, TotpService $totp)
    {
        $secret = $totp->generateSecret();
        return response()->json(['secret' => $secret, 'provisioning_uri' => $totp->provisioningUri($secret, $request->user()->email)]);
    }

    public function confirm(Request $request, TotpService $totp)
    {
        $data = $request->validate(['secret' => ['required', 'string', 'size:32'], 'code' => ['required', 'digits:6']]);
        abort_unless($totp->verify($data['secret'], $data['code']), 422, 'The authenticator code is invalid.');
        $request->user()->update(['two_factor_secret' => $data['secret'], 'two_factor_confirmed_at' => now()]);
        return response()->json(['message' => 'Two-factor authentication enabled.']);
    }

    public function disable(Request $request, TotpService $totp)
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'digits:6']]);
        abort_unless(Hash::check($data['password'], $request->user()->password), 422, 'The password is incorrect.');
        abort_unless($totp->verify($request->user()->two_factor_secret, $data['code']), 422, 'The authenticator code is invalid.');
        $request->user()->update(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
        $request->user()->tokens()->whereKeyNot($request->user()->currentAccessToken()->id)->delete();
        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }
}
