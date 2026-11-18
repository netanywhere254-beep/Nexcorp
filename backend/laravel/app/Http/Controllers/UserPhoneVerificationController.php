<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserPhoneVerificationController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $verification = DB::table('user_phone_verifications')
            ->where('user_id', $user->id)
            ->where('phone_e164', $user->phone)
            ->first();

        return response()->json([
            'phone' => $user->phone,
            'verified' => (bool) ($verification && $verification->verified_at),
            'verified_at' => $verification?->verified_at,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ]);
        $user = $request->user();
        $phone = $data['phone'];

        if ($user->phone === $phone && DB::table('user_phone_verifications')
            ->where('user_id', $user->id)->where('phone_e164', $phone)->exists()) {
            return response()->json(['message' => 'This account phone number is already verified.']);
        }

        $claimed = DB::table('user_phone_verifications')
            ->where('phone_e164', $phone)->where('user_id', '!=', $user->id)->exists();
        if ($claimed) {
            throw ValidationException::withMessages(['phone' => 'This phone number is already verified on another account.']);
        }

        $latest = DB::table('user_phone_otp_challenges')
            ->where('user_id', $user->id)->latest('id')->first();
        if ($latest && now()->lessThan(\Illuminate\Support\Carbon::parse($latest->last_sent_at)->addSeconds(60))) {
            return response()->json(['message' => 'Please wait before requesting another code.'], 429);
        }

        $url = (string) config('services.nexora_public_sms.url');
        if (! Str::startsWith($url, 'https://')) {
            return response()->json(['message' => 'Account phone verification delivery is not configured yet.'], 503);
        }

        $code = (string) random_int(100000, 999999);
        $http = Http::timeout(10)->acceptJson();
        $token = (string) config('services.nexora_public_sms.token');
        if ($token !== '') {
            $http = $http->withToken($token);
        }

        try {
            $delivery = $http->post($url, [
                'to' => $phone,
                'message' => 'Your Nexora account verification code is '.$code.'. It expires in 5 minutes.',
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'We could not deliver the verification code. Please try again later.'], 502);
        }

        if (! $delivery->successful()) {
            return response()->json(['message' => 'We could not deliver the verification code. Please try again later.'], 502);
        }

        DB::transaction(function () use ($user, $phone, $code) {
            DB::table('user_phone_otp_challenges')->where('user_id', $user->id)
                ->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
            DB::table('user_phone_otp_challenges')->insert([
                'user_id' => $user->id,
                'phone_e164' => $phone,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(5),
                'last_sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Verification code sent. It expires in 5 minutes.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'code' => ['required', 'digits:6'],
        ]);
        $user = $request->user();

        $result = DB::transaction(function () use ($user, $data) {
            $challenge = DB::table('user_phone_otp_challenges')
                ->where('user_id', $user->id)
                ->where('phone_e164', $data['phone'])
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->verified_at
                || now()->greaterThan($challenge->expires_at) || $challenge->attempts >= 5) {
                throw ValidationException::withMessages(['code' => 'The code is invalid, expired, or already used.']);
            }

            if (! Hash::check($data['code'], $challenge->code_hash)) {
                DB::table('user_phone_otp_challenges')->where('id', $challenge->id)
                    ->increment('attempts');
                throw ValidationException::withMessages(['code' => 'The code is invalid or expired.']);
            }

            $claimed = DB::table('user_phone_verifications')
                ->where('phone_e164', $data['phone'])
                ->where('user_id', '!=', $user->id)
                ->exists();
            if ($claimed) {
                throw ValidationException::withMessages(['phone' => 'This phone number is already verified on another account.']);
            }

            DB::table('user_phone_verifications')->updateOrInsert(
                ['user_id' => $user->id],
                ['phone_e164' => $data['phone'], 'verified_at' => now(), 'updated_at' => now(), 'created_at' => now()]
            );
            DB::table('user_phone_otp_challenges')->where('id', $challenge->id)
                ->update(['verified_at' => now(), 'consumed_at' => now(), 'updated_at' => now()]);

            if ($user->phone !== $data['phone']) {
                $user->forceFill(['phone' => $data['phone']])->save();
                // Trace & Find has its own independent phone verification and must re-verify changes.
                $user->traceProfile()->update(['phone_verification_status' => 'pending']);
            }

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $user->id,
                'action' => 'account.phone_verified',
                'subject_type' => get_class($user),
                'subject_id' => (string) $user->id,
                'metadata' => ['phone_last_four' => substr($data['phone'], -4)],
            ]);

            return true;
        });

        return response()->json(['message' => 'Account phone number verified successfully.', 'verified' => $result]);
    }
}
