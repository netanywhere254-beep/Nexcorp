<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TraceProfileController extends Controller
{
    /**
     * Public self-service onboarding creates family profiles only.
     * Trusted authority, organisation, volunteer, and investigator profiles
     * must be provisioned through the separately audited vetting workflow.
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
            'selfie' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'location_consent' => ['accepted'],
            'biometric_processing_consent' => ['accepted'],
        ]);

        $selfiePath = $request->file('selfie')->store('trace-find/identity/pending', 'local');

        $profile = $user->traceProfile()->create([
            'country_code' => strtoupper($data['country_code']),
            'region_code' => $data['region_code'],
            'locality' => $data['locality'] ?? null,
            'account_type' => 'family',
            'verification_status' => 'pending',
            'phone_verification_status' => filled($user->phone) ? 'pending' : 'missing',
            'location_verification_status' => 'pending',
            'selfie_verification_status' => 'pending',
            'selfie_path' => $selfiePath,
            // Submitted coordinates are evidence for review, not verified coordinates.
        ]);

        return response()->json([
            'message' => 'Profile submitted for identity, location and selfie review.',
            'profile' => [
                'country_code' => $profile->country_code,
                'region_code' => $profile->region_code,
                'verification_status' => $profile->verification_status,
            ],
        ], 201);
    }
}
