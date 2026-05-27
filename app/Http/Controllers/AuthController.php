<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['sometimes', 'in:customer,technician,admin'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => Str::lower($validated['email']),
            'password' => $validated['password'],
            'role' => $validated['role'] ?? 'customer',
        ]);

        return response()->json($this->authPayload($user), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', Str::lower($credentials['email']))
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The credentials provided do not match our records.'],
            ]);
        }

        return response()->json($this->authPayload($user));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->userFromBearerToken($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json(['user' => $this->serializeUser($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $plainTextToken = $request->bearerToken();

        if ($plainTextToken) {
            DB::table('personal_access_tokens')
                ->where('token', hash('sha256', $plainTextToken))
                ->delete();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function authPayload(User $user): array
    {
        return [
            'user' => $this->serializeUser($user),
            'access_token' => $this->createToken($user),
        ];
    }

    private function createToken(User $user): string
    {
        $plainTextToken = Str::random(80);

        DB::table('personal_access_tokens')->insert([
            'user_id' => $user->id,
            'name' => 'nextjs-web',
            'token' => hash('sha256', $plainTextToken),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plainTextToken;
    }

    private function userFromBearerToken(Request $request): ?User
    {
        $plainTextToken = $request->bearerToken();

        if (! $plainTextToken) {
            return null;
        }

        $token = DB::table('personal_access_tokens')
            ->where('token', hash('sha256', $plainTextToken))
            ->first();

        if (! $token) {
            return null;
        }

        DB::table('personal_access_tokens')
            ->where('id', $token->id)
            ->update(['last_used_at' => now(), 'updated_at' => now()]);

        return User::query()->find($token->user_id);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?? 'customer',
            'avatar' => '',
            'addresses' => [],
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
