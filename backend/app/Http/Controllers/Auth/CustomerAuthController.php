<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterCustomerRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CustomerAuthController extends Controller
{
    public function register(RegisterCustomerRequest $request)
    {
        $data = $request->validated();

        $customer = Customer::create([
            'full_name' => $data['full_name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'username' => $data['username'],
            'password' => Hash::make($data['password']),
            'status' => 'inactive', // becomes 'active' once a subscription activates
            'home_router_id' => $data['router_id'] ?? null,
        ]);

        $token = $customer->createToken('customer-token', ['customer'])->plainTextToken;

        ActivityLog::record('customer.register', "Customer {$customer->username} registered.", ['customer_id' => $customer->id]);

        return response()->json([
            'token' => $token,
            'customer' => $customer,
        ], 201);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid input.', 'errors' => $validator->errors()], 422);
        }

        // Allow login by username OR phone — the login form labels this
        // "Username or Phone" (see frontend/src/pages/customer/Login.jsx).
        $customer = Customer::where('username', $request->username)
            ->orWhere('phone', $request->username)
            ->first();

        if (! $customer || ! Hash::check($request->password, $customer->password)) {
            return response()->json(['message' => 'These credentials do not match our records.'], 401);
        }

        if ($customer->status === 'suspended') {
            return response()->json(['message' => 'Your account has been suspended. Contact support.'], 403);
        }

        $token = $customer->createToken('customer-token', ['customer'])->plainTextToken;

        ActivityLog::record('customer.login', "Customer {$customer->username} logged in.", ['customer_id' => $customer->id]);

        return response()->json([
            'token' => $token,
            'customer' => $customer,
        ]);
    }

    public function logout(Request $request)
    {
        $customer = $request->user();

        ActivityLog::record('customer.logout', "Customer {$customer->username} logged out.", ['customer_id' => $customer->id]);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
