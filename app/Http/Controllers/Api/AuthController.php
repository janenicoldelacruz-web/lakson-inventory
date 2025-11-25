<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // ================== COMMON HELPERS ==================

    /**
     * Helper for role-based registration that uses
     * name, email, password + password_confirmation
     * (used by OWNER registration).
     */

    /**
     * Helper for role-based login (email + password, with role check).
     */
    protected function loginWithRole(Request $request, string $role)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var \App\Models\User|null $user */
        $user = User::where('email', $credentials['email'])
            ->where('role', $role)  // ENSURE ROLE MATCHES ENDPOINT
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials or role.'],
            ]);
        }

        // Optional: revoke existing tokens so only one session
        $user->tokens()->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    // ================== OWNER (WEB) ==================
    public function ownerLogin(Request $request)
    {
        return $this->loginWithRole($request, 'owner');
    }

    // ================== CUSTOMER (ANDROID) ==================

    public function customerRegister(Request $request)
    {
        // Explicit rules for customers:
        // name, email, phone, address, password
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone'    => ['required', 'string', 'max:50'],
            'address'  => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6'],
            // no "confirmed" rule here for Android
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'phone'    => $data['phone'],
            'address'  => $data['address'],
            'password' => Hash::make($data['password']),
            'role'     => 'customer',   // FORCE customer role
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Customer registered successfully.',
            'user'    => $user,
            'token'   => $token,
        ], 201);
    }

    public function customerLogin(Request $request)
    {
        return $this->loginWithRole($request, 'customer');
    }

    // ================== SHARED AUTH ==================

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

public function logout(Request $request)
{
    $token = $request->user()->currentAccessToken();

    if ($token) {
        $token->delete();
    }

    return response()->json([
        'message' => 'Logged out successfully.',
    ]);
}

}
