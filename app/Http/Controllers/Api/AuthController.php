<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau kata sandi tidak sesuai.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Berhasil masuk.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->load('branch.branchType'),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Berhasil keluar.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->load('branch.branchType'),
        ]);
    }

    public function confirmPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => ['Kata sandi konfirmasi tidak sesuai.'],
            ]);
        }

        return response()->json([
            'message' => 'Kata sandi terverifikasi.',
            'confirmed' => true,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'current_password' => ['required', 'string'],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Kata sandi saat ini tidak sesuai.'],
            ]);
        }

        $user->name = $data['name'];
        $user->email = $data['email'];

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        return response()->json([
            'message' => empty($data['password'])
                ? 'Profil berhasil diperbarui.'
                : 'Profil dan kata sandi berhasil diperbarui.',
            'data' => $user->fresh()->load('branch.branchType'),
        ]);
    }

    /**
     * Daftar akun uji dari database — hanya untuk lingkungan local/debug (sementara).
     */
    public function demoAccounts(): JsonResponse
    {
        if (! app()->environment('local') && ! config('app.debug')) {
            return response()->json(['message' => 'Tidak tersedia.'], 404);
        }

        $users = User::query()
            ->with('branch:id,name')
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'branch_id']);

        return response()->json([
            'message' => 'Akun uji (sementara).',
            'password_hint' => 'password',
            'data' => $users->map(fn (User $u) => [
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'role_label' => $u->role === 'owner' ? 'Pemilik' : 'Admin',
                'branch' => $u->branch?->name,
            ]),
        ]);
    }
}
