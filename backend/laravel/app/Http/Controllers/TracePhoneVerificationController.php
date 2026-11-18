<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TracePhoneVerificationController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ]);

        $url = config('services.trace_find_sms.url');
        if (!is_string($url) || !Str::startsWith($url, ['https://'])) {
            return response()->json(['message' => 'Phone verification delivery is not configured yet. Please try again later.'], 503);
        }

        $user = $request->user();
        $phone = $data['phone'];
        $latest = \DB::table('trace_phone_verifications')
            ->where('user_id', $user->id)->where('phone', $phone)
            ->latest('id')->first();

        if ($latest && $latest->last_sent_at && \Illuminate\Support\Carbon::parse($latest->last_sent_at)->greaterThan(now()->subSeconds(60))) {
            return response()->json(['message' => 'Please wait before requesting another code.'], 429);
        }

        $code = (string) random_int(100000, 999999);
        $message = 'Your Loopza Trace & Find verification code is '.$code.'. It expires in 10 minutes.';
        $http = Http::timeout(10);
        $token = config('services.trace_find_sms.token');
        if (is_string($token) && $token !== '') {
            $http = $http->withToken($token);
        }

        try {
            $delivery = $http->post($url, ['to' => $phone, 'message' => $message]);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'We could not deliver the verification code. Please try again later.'], 502);
        }

        if (!$delivery->successful()) {
            return response()->json(['message' => 'We could not deliver the verification code. Please try again later.'], 502);
        }

        if ($user->phone !== $phone) {
            $user->traceProfile()->update(['phone_verification_status' => 'pending']);
        }

        \DB::table('trace_phone_verifications')->insert([
            'user_id' => $user->id,
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'last_sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'A verification code has been sent. It expires in 10 minutes.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();
        $challenge = \DB::table('trace_phone_verifications')
            ->where('user_id', $user->id)
            ->where('phone', $data['phone'])
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (!$challenge || now()->greaterThan($challenge->expires_at) || $challenge->attempts >= 5) {
            throw ValidationException::withMessages(['code' => 'The code is invalid or expired. Request a new code.']);
        }

        if (!Hash::check($data['code'], $challenge->code_hash)) {
            \DB::table('trace_phone_verifications')->where('id', $challenge->id)->increment('attempts');
            throw ValidationException::withMessages(['code' => 'The code is invalid or expired.']);
        }

        \DB::transaction(function () use ($challenge, $user, $data) {
            \DB::table('trace_phone_verifications')->where('id', $challenge->id)
                ->update(['verified_at' => now(), 'updated_at' => now()]);
            $user->forceFill(['phone' => $data['phone']])->save();
            $user->traceProfile()->update(['phone_verification_status' => 'verified']);
        });

        return response()->json(['message' => 'Phone number verified successfully.']);
    }
}
