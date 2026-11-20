<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GroupAuthority;
use App\Models\TraceAccessApplication;
use App\Models\TraceCountryAccessGrant;
use App\Services\GroupStaffOtpConfirmation;
use App\Services\TraceReviewerCountryScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TraceAccessApplicationController extends Controller
{
    private function assertAuthorisedReviewer(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->status === 'active', 403);

        $allowed = GroupAuthority::query()
            ->where('user_id', $user->id)
            ->whereIn('authority', ['GROUP_SECURITY_OFFICER', 'GROUP_CEO'])
            ->where('scope_type', 'group')
            ->where('scope_key', 'nexora')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        abort_unless($allowed, 403, 'An active Nexora Group Security Officer or Group CEO appointment is required.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'requested_role' => ['required', Rule::in(['authority', 'professional_investigator', 'volunteer'])],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'organisation_name' => ['required_if:requested_role,authority,professional_investigator', 'nullable', 'string', 'max:180'],
            'organisation_registration' => ['required_if:requested_role,authority,professional_investigator', 'nullable', 'string', 'max:120'],
            'credentials_summary' => ['required_if:requested_role,authority,professional_investigator', 'nullable', 'string', 'min:20', 'max:4000'],
            'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $alreadyApproved = TraceAccessApplication::query()
            ->where('user_id', $request->user()->id)
            ->where('requested_role', $data['requested_role'])
            ->where('country_code', strtoupper($data['country_code']))
            ->where('status', 'approved')
            ->exists();
        abort_if($alreadyApproved, 409, 'This role is already approved for the selected country.');

        $existingPending = TraceAccessApplication::query()
            ->where('user_id', $request->user()->id)
            ->where('requested_role', $data['requested_role'])
            ->where('country_code', strtoupper($data['country_code']))
            ->where('status', 'pending_review')
            ->exists();
        abort_if($existingPending, 409, 'An application for this role and country is already awaiting review.');

        $path = $request->file('evidence')->store('trace-find/access-applications/'.$request->user()->id, 'local');

        try {
            $application = TraceAccessApplication::create([
                'user_id' => $request->user()->id,
                'requested_role' => $data['requested_role'],
                'country_code' => strtoupper($data['country_code']),
                'organisation_name' => $data['organisation_name'] ?? null,
                'organisation_registration' => $data['organisation_registration'] ?? null,
                'credentials_summary' => $data['credentials_summary'] ?? null,
                'evidence_path' => $path,
                'status' => 'pending_review',
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'message' => 'Application received for human vetting. No professional or authority permissions have been granted.',
            'application' => [
                'id' => $application->id,
                'requested_role' => $application->requested_role,
                'country_code' => $application->country_code,
                'status' => $application->status,
            ],
        ], 201);
    }

    public function index(Request $request, GroupStaffOtpConfirmation $otp, TraceReviewerCountryScope $scope): JsonResponse
    {
        $this->assertAuthorisedReviewer($request);
        $countries = $scope->countriesFor($request->user());
        abort_if($countries === [], 403, 'No active country-scoped Trace & Find reviewer appointment exists.');
        $otp->consume($request, 'loopza.trace.access_applications.queue');

        AuditLog::create([
            'organization_id' => null,
            'actor_user_id' => $request->user()->id,
            'action' => 'loopza.trace_access_application.queue.accessed',
            'subject_type' => TraceAccessApplication::class,
            'subject_id' => null,
            'metadata' => ['accessed_at' => now()->toISOString()],
        ]);

        $applications = TraceAccessApplication::query()
            ->with('applicant:id,name,email')
            ->whereIn('country_code', $countries)
            ->where('user_id', '!=', $request->user()->id)
            ->whereIn('status', ['pending_review', 'needs_more_information'])
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $applications->map(fn ($app) => [
            'id' => $app->id,
            'applicant' => ['id' => $app->applicant->id, 'name' => $app->applicant->name, 'email' => $app->applicant->email],
            'requested_role' => $app->requested_role,
            'country_code' => $app->country_code,
            'organisation_name' => $app->organisation_name,
            'organisation_registration' => $app->organisation_registration,
            'credentials_summary' => $app->credentials_summary,
            'status' => $app->status,
            'submitted_at' => $app->created_at?->toISOString(),
        ])]);
    }

    public function evidence(Request $request, GroupStaffOtpConfirmation $otp, TraceReviewerCountryScope $scope, int $applicationId): StreamedResponse
    {
        $this->assertAuthorisedReviewer($request);
        $countries = $scope->countriesFor($request->user());
        abort_if($countries === [], 403);
        $otp->consume($request, 'loopza.trace.access_applications.evidence.'.$applicationId);

        $application = TraceAccessApplication::query()->whereIn('country_code', $countries)->findOrFail($applicationId);
        abort_if((int) $application->user_id === (int) $request->user()->id, 403,
            'Reviewers cannot access evidence attached to their own access applications.');
        abort_unless(in_array($application->status, ['pending_review', 'needs_more_information'], true), 404);
        abort_unless($application->evidence_path && Storage::disk('local')->exists($application->evidence_path), 404);

        AuditLog::create([
            'organization_id' => null,
            'actor_user_id' => $request->user()->id,
            'action' => 'loopza.trace_access_application.evidence.accessed',
            'subject_type' => TraceAccessApplication::class,
            'subject_id' => (string) $application->id,
            'metadata' => ['accessed_at' => now()->toISOString()],
        ]);

        return Storage::disk('local')->response($application->evidence_path, 'verification-evidence', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'attachment');
    }

    public function decide(Request $request, GroupStaffOtpConfirmation $otp, TraceReviewerCountryScope $scope, int $applicationId): JsonResponse
    {
        $this->assertAuthorisedReviewer($request);
        $countries = $scope->countriesFor($request->user());
        abort_if($countries === [], 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'request_information'])],
            'notes' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $otp->consume($request, 'loopza.trace.access_applications.decision.'.$applicationId);

        $application = DB::transaction(function () use ($request, $applicationId, $data, $countries): TraceAccessApplication {
            $app = TraceAccessApplication::query()->whereIn('country_code', $countries)->lockForUpdate()->findOrFail($applicationId);
            abort_unless(in_array($app->status, ['pending_review', 'needs_more_information'], true), 409,
                'This application is no longer awaiting a decision.');
            abort_if((int) $app->user_id === (int) $request->user()->id, 403,
                'Reviewers cannot approve, reject or otherwise decide their own access applications.');

            $status = match ($data['decision']) {
                'approve' => 'approved',
                'reject' => 'rejected',
                default => 'needs_more_information',
            };

            $app->update([
                'status' => $status,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['notes'],
            ]);

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $request->user()->id,
                'action' => 'loopza.trace_access_application.reviewed',
                'subject_type' => TraceAccessApplication::class,
                'subject_id' => (string) $app->id,
                'metadata' => [
                    'requested_role' => $app->requested_role,
                    'country_code' => $app->country_code,
                    'decision' => $data['decision'],
                    'resulting_status' => $status,
                ],
            ]);

            return $app->fresh();
        });

        return response()->json([
            'message' => 'Vetting decision recorded. Approval records verification only; case access remains separately permissioned.',
            'application' => [
                'id' => $application->id,
                'requested_role' => $application->requested_role,
                'country_code' => $application->country_code,
                'status' => $application->status,
                'reviewed_at' => $application->reviewed_at?->toISOString(),
            ],
        ]);
    }


    public function grantCountryAccess(Request $request, GroupStaffOtpConfirmation $otp, TraceReviewerCountryScope $scope, int $applicationId): JsonResponse
    {
        $this->assertAuthorisedReviewer($request);
        $countries = $scope->countriesFor($request->user());
        abort_if($countries === [], 403);
        $application = TraceAccessApplication::query()->whereIn('country_code', $countries)->findOrFail($applicationId);
        abort_unless($application->status === 'approved', 409,
            'The application must first pass human vetting. Vetting approval alone does not grant access.');

        $allowedByRole = [
            'authority' => ['cases.review', 'cases.read', 'documents.read', 'authority.confirmation.submit'],
            'professional_investigator' => ['cases.read', 'cases.match.submit', 'documents.read'],
            'volunteer' => ['public_tasks.view', 'public_tasks.submit'],
        ];
        $allowed = $allowedByRole[$application->requested_role] ?? [];
        $data = $request->validate([
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', Rule::in($allowed)],
            'region_code' => ['nullable', 'string', 'max:64'],
            'expires_at' => ['required', 'date', 'after:now'],
        ]);

        if (filled($data['region_code'] ?? null)) {
            $regionCode = strtoupper(trim($data['region_code']));
            abort_if(
                preg_match('/^[A-Z]{2}-[A-Z0-9-]{1,12}$/', $regionCode) !== 1
                    || ! str_starts_with($regionCode, strtoupper($application->country_code).'-'),
                422,
                'The region code must be a valid country-prefixed code belonging to the application country.'
            );
            $data['region_code'] = $regionCode;
        }

        $otp->consume($request, 'loopza.trace.access_applications.grant.'.$applicationId);

        $grant = DB::transaction(function () use ($request, $applicationId, $data, $countries): TraceCountryAccessGrant {
            // Re-read and lock the approved application inside the grant transaction.
            // This prevents a concurrent reviewer action from changing its status
            // between the initial check and creation of the country-scoped grant.
            $lockedApplication = TraceAccessApplication::query()
                ->whereIn('country_code', $countries)
                ->lockForUpdate()
                ->findOrFail($applicationId);

            abort_unless($lockedApplication->status === 'approved', 409,
                'The application must remain approved when the access grant is created.');
            abort_if((int) $lockedApplication->user_id === (int) $request->user()->id, 403,
                'Reviewers cannot grant access to their own accounts.');

            $grant = TraceCountryAccessGrant::create([
                'user_id' => $lockedApplication->user_id,
                'source_application_id' => $lockedApplication->id,
                'access_role' => $lockedApplication->requested_role,
                'country_code' => $lockedApplication->country_code,
                'region_code' => $data['region_code'] ?? null,
                'permissions' => array_values(array_unique($data['permissions'])),
                'status' => 'active',
                'granted_by' => $request->user()->id,
                'expires_at' => $data['expires_at'],
            ]);

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $request->user()->id,
                'action' => 'loopza.trace_country_access.granted',
                'subject_type' => TraceCountryAccessGrant::class,
                'subject_id' => (string) $grant->id,
                'metadata' => [
                    'application_id' => $lockedApplication->id,
                    'user_id' => $lockedApplication->user_id,
                    'role' => $lockedApplication->requested_role,
                    'country_code' => $lockedApplication->country_code,
                    'region_code' => $grant->region_code,
                    'permissions' => $grant->permissions,
                    'expires_at' => $grant->expires_at?->toISOString(),
                ],
            ]);

            return $grant;
        });

        return response()->json([
            'message' => 'Country-scoped access grant created. Access is limited to the selected country, optional region and listed permissions.',
            'grant' => [
                'id' => $grant->id,
                'user_id' => $grant->user_id,
                'role' => $grant->access_role,
                'country_code' => $grant->country_code,
                'region_code' => $grant->region_code,
                'permissions' => $grant->permissions,
                'status' => $grant->status,
                'expires_at' => $grant->expires_at?->toISOString(),
            ],
        ], 201);
    }



    public function revokeCountryAccess(Request $request, GroupStaffOtpConfirmation $otp, TraceReviewerCountryScope $scope, int $grantId): JsonResponse
    {
        $this->assertAuthorisedReviewer($request);
        $countries = $scope->countriesFor($request->user());
        abort_if($countries === [], 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $otp->consume($request, 'loopza.trace.country_access.revoke.'.$grantId);

        $grant = DB::transaction(function () use ($request, $grantId, $data, $countries): TraceCountryAccessGrant {
            $grant = TraceCountryAccessGrant::query()->whereIn('country_code', $countries)->lockForUpdate()->findOrFail($grantId);
            abort_unless($grant->status === 'active', 409, 'Only active grants can be revoked.');

            $grant->update([
                'status' => 'revoked',
                'revoked_at' => now(),
            ]);

            AuditLog::create([
                'organization_id' => null,
                'actor_user_id' => $request->user()->id,
                'action' => 'loopza.trace_country_access.revoked',
                'subject_type' => TraceCountryAccessGrant::class,
                'subject_id' => (string) $grant->id,
                'metadata' => [
                    'user_id' => $grant->user_id,
                    'source_application_id' => $grant->source_application_id,
                    'access_role' => $grant->access_role,
                    'country_code' => $grant->country_code,
                    'reason' => $data['reason'],
                ],
            ]);

            return $grant->fresh();
        });

        return response()->json([
            'message' => 'Country-scoped access grant revoked.',
            'grant' => [
                'id' => $grant->id,
                'status' => $grant->status,
                'revoked_at' => $grant->revoked_at?->toISOString(),
            ],
        ]);
    }

}
