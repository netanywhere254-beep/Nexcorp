<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GroupAuthority;
use App\Models\TraceCase;
use App\Models\TraceCaseDocument;
use App\Models\TraceProfile;
use App\Services\GroupStaffOtpConfirmation;
use App\Services\TraceReviewerCountryScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TraceReviewController extends Controller
{
    private function assertReviewer(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->status === 'active', 403);

        $appointed = GroupAuthority::query()
            ->where('user_id', $user->id)
            ->whereIn('authority', ['GROUP_SECURITY_OFFICER', 'GROUP_CEO'])
            ->where('scope_type', 'group')
            ->where('scope_key', 'nexora')
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        abort_unless($appointed, 403, 'An active Nexora Group Security Officer appointment is required.');
    }

    private function reviewerCountries(Request $request, TraceReviewerCountryScope $scope): array
    {
        $countries = $scope->countriesFor($request->user());
        abort_if($countries === [], 403, 'No active country-scoped Trace & Find reviewer appointment exists.');
        return $countries;
    }

    private function auditSensitiveRead(Request $request, string $action, string $subjectType, ?string $subjectId = null): void
    {
        AuditLog::create([
            'organization_id' => null,
            'actor_user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => ['accessed_at' => now()->toISOString()],
        ]);
    }

    public function profileQueue(Request $request, GroupStaffOtpConfirmation $otp, TraceReviewerCountryScope $scope): JsonResponse
    {
        $this->assertReviewer($request);
        $countries = $this->reviewerCountries($request, $scope);
        $otp->consume($request, 'loopza.trace.review.profiles.queue');
        $this->auditSensitiveRead($request, 'loopza.trace_profile.review_queue.accessed', TraceProfile::class);

        $profiles = TraceProfile::query()
            ->with('user:id,name,email,phone')
            ->where('account_type', 'family')
            ->whereIn('country_code', $countries)
            ->whereNotNull('selfie_path')
            ->whereNotNull('location_consent_at')
            ->whereNotNull('biometric_processing_consent_at')
            ->whereIn('verification_status', ['pending', 'in_review'])
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $profiles->map(fn (TraceProfile $profile) => [
                'id' => $profile->id,
                'user' => [
                    'id' => $profile->user->id,
                    'name' => $profile->user->name,
                    'email' => $profile->user->email,
                    'phone_last_four' => $profile->user->phone ? substr($profile->user->phone, -4) : null,
                ],
                'country_code' => $profile->country_code,
                'region_code' => $profile->region_code,
                'locality' => $profile->locality,
                'identity_status' => $profile->identity_verification_status,
                'location_status' => $profile->location_verification_status,
                'selfie_status' => $profile->selfie_verification_status,
                'selfie_submitted' => filled($profile->selfie_path),
                'submitted_coordinates' => [
                    'latitude' => $profile->submitted_latitude,
                    'longitude' => $profile->submitted_longitude,
                ],
                'submitted_at' => $profile->created_at?->toISOString(),
            ]),
        ]);
    }

    public function selfie(
        Request $request,
        GroupStaffOtpConfirmation $otp,
        int $profileId,
        TraceReviewerCountryScope $scope
    ): StreamedResponse {
        $this->assertReviewer($request);
        $countries = $this->reviewerCountries($request, $scope);
        $otp->consume($request, 'loopza.trace.review.profile.selfie.'.$profileId);

        $profile = TraceProfile::query()->whereIn('country_code', $countries)->findOrFail($profileId);
        abort_if((int) $profile->user_id === (int) $request->user()->id, 403,
            'Reviewers cannot access their own identity verification evidence.');
        abort_unless($profile->account_type === 'family', 404);
        abort_unless(in_array($profile->verification_status, ['pending', 'in_review'], true), 404);
        abort_unless(filled($profile->selfie_path), 404);
        abort_unless(Storage::disk('local')->exists($profile->selfie_path), 404);
        $this->auditSensitiveRead($request, 'loopza.trace_profile.selfie.accessed', TraceProfile::class, (string) $profile->id);

        return Storage::disk('local')->response(
            $profile->selfie_path,
            null,
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Pragma' => 'no-cache',
            ],
            'inline'
        );
    }

    public function decideProfile(
        Request $request,
        GroupStaffOtpConfirmation $otp,
        int $profileId,
        TraceReviewerCountryScope $scope
    ): JsonResponse {
        $this->assertReviewer($request);
        $countries = $this->reviewerCountries($request, $scope);

        $data = $request->validate([
            'identity_decision' => ['required', Rule::in(['approved', 'rejected'])],
            'location_decision' => ['required', Rule::in(['approved', 'rejected'])],
            'selfie_decision' => ['required', Rule::in(['approved', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $otp->consume($request, 'loopza.trace.review.profile.'.$profileId);

        $profile = DB::transaction(function () use ($request, $profileId, $data, $countries): TraceProfile {
            $profile = TraceProfile::query()->whereIn('country_code', $countries)->lockForUpdate()->findOrFail($profileId);
            abort_if((int) $profile->user_id === (int) $request->user()->id, 403,
                'Reviewers cannot decide their own identity verification.');
            abort_unless($profile->account_type === 'family', 404);
            abort_unless(filled($profile->selfie_path) && $profile->location_consent_at && $profile->biometric_processing_consent_at, 409,
                'The location and selfie consent steps must be completed before profile review.');
            abort_unless(in_array($profile->verification_status, ['pending', 'in_review'], true), 409,
                'This profile is not awaiting review.');

            $approved = $data['identity_decision'] === 'approved'
                && $data['location_decision'] === 'approved'
                && $data['selfie_decision'] === 'approved';

            $profile->update([
                'identity_verification_status' => $data['identity_decision'] === 'approved' ? 'verified' : 'rejected',
                'location_verification_status' => $data['location_decision'] === 'approved' ? 'verified' : 'rejected',
                'selfie_verification_status' => $data['selfie_decision'] === 'approved' ? 'verified' : 'rejected',
                'verification_status' => $approved ? 'verified' : 'rejected',
                'verified_latitude' => $approved ? $profile->submitted_latitude : null,
                'verified_longitude' => $approved ? $profile->submitted_longitude : null,
                'verified_at' => $approved ? now() : null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['notes'] ?? null,
            ]);

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $request->user()->id,
                'action' => 'loopza.trace_profile.reviewed',
                'subject_type' => TraceProfile::class,
                'subject_id' => (string) $profile->id,
                'metadata' => [
                    'identity_decision' => $data['identity_decision'],
                    'location_decision' => $data['location_decision'],
                    'selfie_decision' => $data['selfie_decision'],
                    'result' => $approved ? 'verified' : 'rejected',
                ],
            ]);

            return $profile->fresh();
        });

        return response()->json([
            'message' => $profile->verification_status === 'verified'
                ? 'Profile verification approved.'
                : 'Profile verification rejected.',
            'profile' => [
                'id' => $profile->id,
                'verification_status' => $profile->verification_status,
                'identity_verification_status' => $profile->identity_verification_status,
                'location_verification_status' => $profile->location_verification_status,
                'selfie_verification_status' => $profile->selfie_verification_status,
                'reviewed_at' => $profile->reviewed_at?->toISOString(),
            ],
        ]);
    }

    public function caseQueue(Request $request, GroupStaffOtpConfirmation $otp, TraceReviewerCountryScope $scope): JsonResponse
    {
        $this->assertReviewer($request);
        $countries = $this->reviewerCountries($request, $scope);
        $otp->consume($request, 'loopza.trace.review.cases.queue');
        $this->auditSensitiveRead($request, 'loopza.trace_case.review_queue.accessed', TraceCase::class);

        $cases = TraceCase::query()
            ->with(['reporter:id,name,email', 'documents:id,trace_case_id,original_filename,mime_type'])
            ->whereIn('incident_country_code', $countries)
            ->where('reporter_user_id', '!=', $request->user()->id)
            ->whereIn('status', ['pending_review', 'cross_region_review', 'urgent_provisional_review', 'needs_more_information'])
            ->orderByDesc('urgent')
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $cases->map(fn (TraceCase $case) => [
                'id' => $case->id,
                'reference' => $case->case_reference,
                'case_type' => $case->case_type,
                'subject_name' => $case->subject_name,
                'subject_age' => $case->subject_age,
                'subject_description' => $case->subject_description,
                'last_seen_details' => $case->last_seen_details,
                'last_seen_at' => $case->last_seen_at?->toISOString(),
                'incident_country_code' => $case->incident_country_code,
                'incident_region_code' => $case->incident_region_code,
                'incident_locality' => $case->incident_locality,
                'status' => $case->status,
                'urgent' => $case->urgent,
                'publication_status' => $case->publication_status,
                'reporter' => [
                    'id' => $case->reporter->id,
                    'name' => $case->reporter->name,
                    'email' => $case->reporter->email,
                ],
                'document_count' => $case->documents()->count(),
                'documents' => $case->documents->map(fn (TraceCaseDocument $document) => [
                    'id' => $document->id,
                    'original_filename' => $document->original_filename,
                    'mime_type' => $document->mime_type,
                ]),
                'submitted_at' => $case->created_at?->toISOString(),
            ]),
        ]);
    }

    public function caseDocument(
        Request $request,
        GroupStaffOtpConfirmation $otp,
        int $caseId,
        int $documentId,
        TraceReviewerCountryScope $scope
    ): StreamedResponse {
        $this->assertReviewer($request);
        $countries = $this->reviewerCountries($request, $scope);
        $otp->consume($request, 'loopza.trace.review.case.document.'.$documentId);

        $case = TraceCase::query()->whereIn('incident_country_code', $countries)->findOrFail($caseId);
        abort_if((int) $case->reporter_user_id === (int) $request->user()->id, 403,
            'Reviewers cannot access evidence attached to their own cases.');
        abort_unless(in_array($case->status, [
            'pending_review', 'cross_region_review', 'urgent_provisional_review', 'needs_more_information',
        ], true), 404);

        $document = TraceCaseDocument::query()
            ->where('trace_case_id', $case->id)
            ->findOrFail($documentId);
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);

        $this->auditSensitiveRead(
            $request,
            'loopza.trace_case.document.accessed',
            TraceCaseDocument::class,
            (string) $document->id
        );

        return Storage::disk('local')->response(
            $document->storage_path,
            $document->original_filename,
            [
                'Content-Type' => $document->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'attachment'
        );
    }

    public function decideCase(
        Request $request,
        GroupStaffOtpConfirmation $otp,
        int $caseId,
        TraceReviewerCountryScope $scope
    ): JsonResponse {
        $this->assertReviewer($request);
        $countries = $this->reviewerCountries($request, $scope);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'request_information'])],
            'notes' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $otp->consume($request, 'loopza.trace.review.case.'.$caseId);

        $case = DB::transaction(function () use ($request, $caseId, $data, $countries): TraceCase {
            $case = TraceCase::query()->whereIn('incident_country_code', $countries)->lockForUpdate()->findOrFail($caseId);
            abort_if((int) $case->reporter_user_id === (int) $request->user()->id, 403,
                'Reviewers cannot decide their own cases.');
            abort_unless(in_array($case->status, [
                'pending_review', 'cross_region_review', 'urgent_provisional_review', 'needs_more_information',
            ], true), 409, 'This case is not awaiting review.');

            $status = match ($data['decision']) {
                'approve' => 'approved_pending_publication',
                'reject' => 'rejected',
                default => 'needs_more_information',
            };

            $case->update([
                'status' => $status,
                'publication_status' => 'private',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['notes'],
            ]);

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $request->user()->id,
                'action' => 'loopza.trace_case.reviewed',
                'subject_type' => TraceCase::class,
                'subject_id' => (string) $case->id,
                'metadata' => [
                    'case_reference' => $case->case_reference,
                    'decision' => $data['decision'],
                    'resulting_status' => $status,
                ],
            ]);

            return $case->fresh();
        });

        return response()->json([
            'message' => 'Case review decision recorded. The case remains private.',
            'case' => [
                'reference' => $case->case_reference,
                'status' => $case->status,
                'publication_status' => $case->publication_status,
                'reviewed_at' => $case->reviewed_at?->toISOString(),
            ],
        ]);
    }
}
