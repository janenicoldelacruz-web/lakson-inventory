<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\LoginHistory;  // Import LoginHistory model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;  // For transactions

class AuthController extends Controller
{
    // ================== COMMON HELPERS ==================

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
            ->where('role', $role)
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials or role.'],
            ]);
        }

        // Define the token variable before the transaction
        $token = null;

        // Wrap user login and login history recording in a transaction
        DB::transaction(function () use ($user, $request, &$token) {
            // Optional: revoke existing tokens so only one session
            $user->tokens()->delete();

            // Create a new token
            $token = $user->createToken('api-token')->plainTextToken;

            // Record login history in system logs
            LoginHistory::create([
                'user_id'    => $user->id,
                'ip_address' => $request->ip(),
                'device'     => $request->header('User-Agent'),
                'login_at'   => now(),
            ]);
        });

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

    // ================== CUSTOMER (ANDROID / FLUTTER) ==================

    public function customerRegister(Request $request)
    {
        // Strict rules for customers: name, email, phone, address, password
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Za-z\s]+$/',   // only letters and spaces
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email'
            ],
            'phone' => [
                'required',
                'regex:/^[0-9]{11}$/',      // EXACTLY 11 digits
            ],
            'address' => [
                'required',
                'string',
                'max:255'
            ],
            'password' => [
                'required',
                'string',
                'min:6',                    // minimum 6 chars
            ],
        ]);

        // We need these outside the transaction
        $user  = null;
        $token = null;

        // Wrap user creation in a transaction
        DB::transaction(function () use ($data, &$user, &$token) {
            $user = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'phone'    => $data['phone'],
                'address'  => $data['address'],
                'password' => Hash::make($data['password']),
                'role'     => 'customer',   // FORCE customer role
            ]);

            // (Optional) revoke any existing tokens (normally none for new user)
            $user->tokens()->delete();

            // Create token for the user
            $token = $user->createToken('api-token')->plainTextToken;
        });

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

    /**
     * GET /api/customer/profile
     * Return the authenticated customer profile.
     */
    public function getProfile(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        // Optional: ensure only customers can access this endpoint
        // if ($user->role !== 'customer') {
        //     return response()->json(['message' => 'Forbidden.'], 403);
        // }

        return response()->json([
            'user' => $user,
        ]);
    }

    /**
     * PUT /api/customer/profile
     * Update basic profile information.
     *
     * Expected body:
     *  - name   (required)
     *  - email  (required, unique except current user)
     *  - phone  (optional)
     *  - address (optional)
     */
    public function updateProfile(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $data = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'phone'   => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $user->name    = $data['name'];
        $user->email   = $data['email'];
        $user->phone   = $data['phone']   ?? $user->phone;
        $user->address = $data['address'] ?? $user->address;
        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $user,
        ]);
    }

    /**
     * POST /api/customer/change-password
     *
     * Body:
     *  - current_password
     *  - new_password
     *  - new_password_confirmation
     */
    public function changePassword(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $data = $request->validate([
            'current_password'          => ['required', 'string'],
            'new_password'              => ['required', 'string', 'min:6', 'confirmed'],
            // "confirmed" looks for new_password_confirmation
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $user->password = Hash::make($data['new_password']);
        $user->save();

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
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
            $token->delete();  // Delete token on logout
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
