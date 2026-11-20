<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TraceCase;
use App\Models\TraceCaseDocument;
use App\Services\TraceCountryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TraceGrantedAccessController extends Controller
{
    public function cases(Request $request, TraceCountryAccess $access): JsonResponse
    {
        $cases = $access->publishedCasesFor($request->user())
            ->orderByDesc('created_at')
            ->paginate(25);

        AuditLog::create([
            'organization_id' => null,
            'actor_user_id' => $request->user()->id,
            'action' => 'loopza.trace_country_access.published_cases.read',
            'subject_type' => TraceCase::class,
            'subject_id' => null,
            'metadata' => ['result_count' => $cases->count(), 'page' => $cases->currentPage()],
        ]);

        return response()->json([
            'data' => $cases->getCollection()->map(fn (TraceCase $case) => [
                'reference' => $case->case_reference,
                'case_type' => $case->case_type,
                'incident_country_code' => $case->incident_country_code,
                'incident_region_code' => $case->incident_region_code,
                'incident_locality' => $case->incident_locality,
                'status' => $case->status,
                'published_at' => $case->updated_at?->toISOString(),
            ]),
            'pagination' => [
                'current_page' => $cases->currentPage(),
                'last_page' => $cases->lastPage(),
                'total' => $cases->total(),
            ],
        ]);
    }

    public function document(
        Request $request,
        TraceCountryAccess $access,
        int $caseId,
        int $documentId
    ): StreamedResponse {
        $case = TraceCase::query()
            ->where('publication_status', 'published')
            ->findOrFail($caseId);

        abort_unless($access->allows($request->user(), 'documents.read', $case), 404);

        $document = TraceCaseDocument::query()
            ->where('trace_case_id', $case->id)
            ->where('verification_status', 'verified')
            ->findOrFail($documentId);

        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);

        AuditLog::create([
            'organization_id' => null,
            'actor_user_id' => $request->user()->id,
            'action' => 'loopza.trace_country_access.published_document.read',
            'subject_type' => TraceCaseDocument::class,
            'subject_id' => (string) $document->id,
            'metadata' => ['case_reference' => $case->case_reference],
        ]);

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
}
