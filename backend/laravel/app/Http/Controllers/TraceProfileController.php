<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TraceProfileController extends Controller
{
    /**
     * Public self-service onboarding creates family profiles only.
     * Trusted authority, organisation, volunteer, and investigator profiles
     * must be provisioned through the separately audited vetting workflow.
     *
     * Location is captured as the first verification step. A selfie is
     * submitted separately through submitSelfie(), so the sequence is explicit.
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
            'phone_verification_status' => filled($user->phone) ? 'pending' : 'missing',
            'location_verification_status' => 'pending',
            'selfie_verification_status' => 'not_submitted',
            'submitted_latitude' => $data['latitude'],
            'submitted_longitude' => $data['longitude'],
            'location_consent_at' => now(),
            'location_submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Location submitted. Complete the next step by submitting your selfie.',
            'next_step' => 'selfie',
            'profile' => [
                'country_code' => $profile->country_code,
                'region_code' => $profile->region_code,
                'verification_status' => $profile->verification_status,
                'location_verification_status' => $profile->location_verification_status,
                'selfie_verification_status' => $profile->selfie_verification_status,
            ],
        ], 201);
    }

    /**
     * Accept the selfie only after the location step has been recorded.
     * Submission does not automatically verify identity or location.
     */
    public function submitSelfie(Request $request): JsonResponse
    {
        $profile = $request->user()->traceProfile;

        abort_unless(
            $profile && $profile->location_submitted_at && $profile->location_consent_at,
            409,
            'Submit and consent to the location step before submitting a selfie.',
        );

        abort_if(
            filled($profile->selfie_path),
            409,
            'A selfie has already been submitted for this profile.',
        );

        $data = $request->validate([
            'selfie' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'biometric_processing_consent' => ['accepted'],
        ]);

        $path = $request->file('selfie')->store(
            'trace-find/identity/pending/'.$request->user()->id,
            'local',
        );

        $profile->update([
            'selfie_path' => $path,
            'selfie_verification_status' => 'pending',
            'biometric_processing_consent_at' => now(),
            'selfie_submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Selfie received after the location step. Location and identity remain pending human review.',
            'profile' => [
                'verification_status' => $profile->fresh()->verification_status,
                'location_verification_status' => $profile->fresh()->location_verification_status,
                'selfie_verification_status' => $profile->fresh()->selfie_verification_status,
            ],
        ], 201);
    }
}
