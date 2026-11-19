<?php

namespace App\\Http\\Controllers;

use App\\Models\\TraceCase;
use App\\Models\\TraceCaseDocument;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Str;
use Illuminate\\Validation\\Rule;
use Illuminate\\Validation\\ValidationException;

class TraceCaseController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->traceProfile;

        if (! $profile || $profile->account_type !== 'family') {
            return response()->json(['message' => 'An individual family profile is required.'], 403);
        }

        if ($profile->phone_verification_status !== 'verified' || ! filled($user->phone)) {
            return response()->json(['message' => 'Verify your contact phone number by OTP before registering a case.'], 403);
        }

        $data = $request->validate([
            'registration_otp' => ['required', 'digits:6'],
            'incident_country_code' => ['required', 'string', 'size:2'],
            'incident_region_code' => ['nullable', 'string', 'max:64'],
            'incident_locality' => ['nullable', 'string', 'max:120'],
            'case_type' => ['sometimes', Rule::in(['missing_person', 'unidentified_person'])],
            'subject_name' => ['required', 'string', 'max:160'],
            'subject_age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'subject_description' => ['required', 'string', 'max:2000'],
            'last_seen_details' => ['required', 'string', 'max:5000'],
            'last_seen_at' => ['nullable', 'date', 'before_or_equal:now'],
            'urgent' => ['sometimes', 'boolean'],
            'authority_reference' => ['nullable', 'string', 'max:120'],
            'authority_reported_at' => ['nullable', 'date', 'before_or_equal:now'],
            'ob_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $sameCountry = strtoupper($data['incident_country_code']) === strtoupper($profile->country_code);
        $sameRegion = $sameCountry && filled($profile->region_code) && filled($data['incident_region_code'] ?? null)
            && hash_equals((string) $profile->region_code, (string) $data['incident_region_code']);
        $locallyVerified = $sameRegion && $profile->verification_status === 'verified'
            && $profile->phone_verification_status === 'verified'
            && $profile->location_verification_status === 'verified'
            && $profile->selfie_verification_status === 'verified';
        $urgent = (bool) ($data['urgent'] ?? false);

        $case = DB::transaction(function () use ($user, $profile, $data, $locallyVerified, $urgent) {
            $challenge = DB::table('trace_case_registration_otps')
                ->where('user_id', $user->id)
                ->where('phone', $user->phone)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $challenge || now()->greaterThan($challenge->expires_at) || $challenge->attempts >= 5) {
                return null;
            }

            if (! Hash::check($data['registration_otp'], $challenge->code_hash)) {
                DB::table('trace_case_registration_otps')->where('id', $challenge->id)->increment('attempts');
                return null;
            }

            DB::table('trace_case_registration_otps')->where('id', $challenge->id)->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

            return TraceCase::create([
                'public_id' => (string) Str::uuid(),
                'case_reference' => 'LTF-'.strtoupper(Str::random(10)),
                'reporter_user_id' => $user->id,
                'reporter_phone' => $user->phone,
                'reporter_country_code' => strtoupper($profile->country_code),
                'reporter_region_code' => $profile->region_code,
                'incident_country_code' => strtoupper($data['incident_country_code']),
                'incident_region_code' => $data['incident_region_code'] ?? null,
                'incident_locality' => $data['incident_locality'] ?? null,
                'case_type' => $data['case_type'] ?? 'missing_person',
                'subject_name' => $data['subject_name'],
                'subject_age' => $data['subject_age'] ?? null,
                'subject_description' => $data['subject_description'],
                'last_seen_details' => $data['last_seen_details'],
                'last_seen_at' => $data['last_seen_at'] ?? null,
                'status' => $locallyVerified ? 'pending_review' : ($urgent ? 'urgent_provisional_review' : 'cross_region_review'),
                'publication_status' => 'private',
                'urgent' => $urgent,
                'authority_reference' => $data['authority_reference'] ?? null,
                'authority_reported_at' => $data['authority_reported_at'] ?? null,
            ]);
        });

        if (! $case) {
            throw ValidationException::withMessages([
                'registration_otp' => 'The case registration code is invalid, expired, already used, or no longer valid for this phone. Request a new code.',
            ]);
        }

        if ($request->hasFile('ob_document')) {
            $file = $request->file('ob_document');
            $path = $file->store("trace-find/cases/{$case->public_id}/restricted", 'local');
            TraceCaseDocument::create([
                'trace_case_id' => $case->id,
                'uploaded_by' => $user->id,
                'document_type' => 'authority_report',
                'storage_path' => $path,
                'original_filename' => basename($file->getClientOriginalName()),
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'verification_status' => 'pending',
            ]);
        }

        return response()->json([
            'message' => 'Report received for review. It is not publicly visible.',
            'case' => [
                'reference' => $case->case_reference,
                'status' => $case->status,
                'publication_status' => $case->publication_status,
                'incident_country_code' => $case->incident_country_code,
                'incident_region_code' => $case->incident_region_code,
            ],
        ], 201);
    }
}
