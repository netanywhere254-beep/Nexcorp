<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordRecoveryController extends Controller
{
    public function sendLink(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:254']]);

        Password::sendResetLink(['email' => Str::lower($validated['email'])]);

        // Deliberately identical for existing and unknown addresses.
        return response()->json([
            'message' => 'If an account exists for that address, a password reset link will be sent.',
        ], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $status = Password::reset(
            [
                'email' => Str::lower($validated['email']),
                'token' => $validated['token'],
                'password' => $validated['password'],
                'password_confirmation' => $validated['password_confirmation'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                AuditLog::create([
                    'organization_id' => null,
                    'actor_user_id' => $user->id,
                    'action' => 'user.password_reset',
                    'subject_type' => User::class,
                    'subject_id' => (string) $user->id,
                    'metadata' => ['source' => 'password_reset_token'],
                ]);

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'The password reset link is invalid or expired.'], 422);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
