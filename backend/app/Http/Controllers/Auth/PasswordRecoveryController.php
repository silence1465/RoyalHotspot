<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PasswordRecoveryController extends Controller
{
    public function forgot(Request $request)
    {
        $startedAt = microtime(true);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'account_type' => ['required', 'in:customer,admin'],
        ]);

        $brokerName = $data['account_type'] === 'admin' ? 'users' : 'customers';
        $model = $data['account_type'] === 'admin' ? User::class : Customer::class;
        $account = $model::where('email', $data['email'])->first();

        if ($account) {
            $token = Password::broker($brokerName)->createToken($account);
            $query = http_build_query([
                'token' => $token,
                'email' => $account->email,
                'type' => $data['account_type'],
            ]);
            $url = rtrim(config('app.frontend_url'), '/').'/reset-password?'.$query;

            try {
                Mail::raw(
                    "Use the link below to reset your Royal Hotspot password.\n\n{$url}\n\nThis link expires in 60 minutes. If you did not request this, ignore this email.",
                    fn ($message) => $message->to($account->email)->subject('Reset your Royal Hotspot password')
                );
            } catch (\Throwable $exception) {
                Log::warning('Password reset email delivery failed.', ['account_type' => $data['account_type']]);
            }
        }

        $remainingMicros = 300000 - (int) ((microtime(true) - $startedAt) * 1000000);
        if ($remainingMicros > 0) usleep($remainingMicros);

        return response()->json([
            'message' => 'If an account matches that email, a password reset link has been sent.',
        ]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'account_type' => ['required', 'in:customer,admin'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $brokerName = $data['account_type'] === 'admin' ? 'users' : 'customers';
        $status = Password::broker($brokerName)->reset(
            [
                'email' => $data['email'],
                'password' => $data['password'],
                'password_confirmation' => $data['password_confirmation'],
                'token' => $data['token'],
            ],
            function ($account, $password) {
                $account->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                $account->tokens()->delete();
                event(new PasswordReset($account));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Your password has been reset. You can now log in.']);
    }
}
