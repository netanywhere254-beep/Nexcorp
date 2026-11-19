<?php

namespace App\\Http\\Controllers;

use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Str;

class TraceCaseRegistrationOtpController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->traceProfile;

        if (! $profile || $profile->phone_verification_status !== 'verified' || ! filled($user->phone)) {
            return response()->json(['message' => 'Verify your Trace & Find contact phone before requesting a case registration code.'], 403);
        }

        $url = config('services.trace_find_sms.url');
        if (! is_string($url) || ! Str::startsWith($url, ['https://'])) {
            return response()->json(['message' => 'Case registration OTP delivery is not configured. Please try again later.'], 503);
        }

        $latest = DB::table('trace_case_registration_otps')
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if ($latest && $latest->last_sent_at && now()->lt(\\Illuminate\\Support\\Carbon::parse($latest->last_sent_at)->addSeconds(60))) {
            return response()->json(['message' => 'Please wait before requesting another case registration code.'], 429);
        }

        $code = (string) random_int(100000, 999999);
        $http = Http::timeout(10);
        $token = config('services.trace_find_sms.token');
        if (is_string($token) && $token !== '') {
            $http = $http->withToken($token);
        }

        try {
            $delivery = $http->post($url, [
                'to' => $user->phone,
                'message' => 'Your Loopza Trace & Find case registration code is '.$code.'. It expires in 5 minutes.',
            ]);
        } catch (\\Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'We could not deliver the case registration code. Please try again later.'], 502);
        }

        if (! $delivery->successful()) {
            return response()->json(['message' => 'We could not deliver the case registration code. Please try again later.'], 502);
        }

        DB::transaction(function () use ($user, $code) {
            DB::table('trace_case_registration_otps')
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            DB::table('trace_case_registration_otps')->insert([
                'user_id' => $user->id,
                'phone' => $user->phone,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(5),
                'last_sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => 'A case registration code was sent to your verified phone. It expires in 5 minutes.']);
    }
}
