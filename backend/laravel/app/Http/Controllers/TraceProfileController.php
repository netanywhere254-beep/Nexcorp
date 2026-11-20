<?php

namespace App\Http\Controllers;

use App\Models\TraceProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TraceProfileController extends Controller
{
    /**
     * Step one: record the family's declared location and explicit consent.
     * A selfie is deliberately not accepted at this stage.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->traceProfile()->exists()) {
            return response()->json(['message' => 'A Trace & Find profile already exists.'], 409);
        }

        $data = $request->validate([
            'country_code' => ['required', 'string', 'size:2'],
            'region_code' => ['required', 'string', 'max:64'],
            'locality' => ['nullable', 'string', 'max:120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_consent' => ['accepted'],
        ]);

        $profile = $user->traceProfile()->create([
            'country_code' => strtoupper($data['country_code']),
            'region_code' => $data['region_code'],
            'locality' => $data['locality'] ?? null,
            'account_type' => 'family',
            'verification_status' => 'pending',
            'identity_verification_status' => 'pending',
            'phone_verification_status' => filled($user->phone) ? 'pending' : 'missing',
            'location_verification_status' => 'pending',
            'selfie_verification_status' => 'pending',
            'submitted_latitude' => $data['latitude'],
            'submitted_longitude' => $data['longitude'],
            'location_consent_at' => now(),
        ]);

        return response()->json([
            'message' => 'Location recorded. Continue to the selfie verification step.',
            'next_step' => 'selfie',
            'profile' => [
                'id' => $profile->id,
                'country_code' => $profile->country_code,
                'region_code' => $profile->region_code,
                'verification_status' => $profile->verification_status,
                'location_verification_status' => $profile->location_verification_status,
                'selfie_verification_status' => $profile->selfie_verification_status,
            ],
        ], 201);
    }

    /**
     * Step two: accept the selfie only after location and consent were saved.
     */
    public function selfie(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->traceProfile;

        abort_unless($profile && $profile->account_type === 'family', 404);
        abort_unless($profile->location_consent_at && $profile->submitted_latitude !== null
            && $profile->submitted_longitude !== null, 409, 'Complete the location step before submitting a selfie.');
        abort_if(filled($profile->selfie_path), 409, 'A selfie has already been submitted.');

        $data = $request->validate([
            'selfie' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'biometric_processing_consent' => ['accepted'],
        ]);

        $path = $request->file('selfie')->store('trace-find/identity/pending', 'local');

        try {
            $profile = DB::transaction(function () use ($user, $data, $path): TraceProfile {
                $locked = TraceProfile::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();

                abort_unless($locked->account_type === 'family' && $locked->location_consent_at
                    && $locked->submitted_latitude !== null && $locked->submitted_longitude !== null,
                    409, 'Complete the location step before submitting a selfie.');
                abort_if(filled($locked->selfie_path), 409, 'A selfie has already been submitted.');

                $locked->update([
                    'selfie_path' => $path,
                    'selfie_submitted_at' => now(),
                    'biometric_processing_consent_at' => now(),
                    'selfie_verification_status' => 'pending',
                    'identity_verification_status' => 'pending',
                    'verification_status' => 'pending',
                ]);

                return $locked->fresh();
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'message' => 'Selfie submitted. Your profile is pending human verification.',
            'profile' => [
                'id' => $profile->id,
                'verification_status' => $profile->verification_status,
                'selfie_verification_status' => $profile->selfie_verification_status,
                'selfie_submitted_at' => $profile->selfie_submitted_at?->toISOString(),
            ],
        ], 201);
    }
}
