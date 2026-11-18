<?php

namespace App\Http\Controllers;

use App\Services\NexCoreAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class NexCoreAiController extends Controller
{
    public function assist(Request $request, NexCoreAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'feature' => ['required', 'string', 'in:general,nexisp.support,loopza.marketing'],
            'message' => ['required', 'string', 'min:2', 'max:4000'],
        ]);

        $organization = $request->attributes->get('activeOrganization');
        abort_unless($organization, 409, 'An active organization context is required.');

        try {
            $result = $ai->assist($organization, $request->user(), $validated['feature'], $validated['message']);

            return response()->json(['status' => 'success', 'data' => $result]);
        } catch (RuntimeException $exception) {
            $status = match ($exception->getMessage()) {
                'NexCore AI is not configured.' => 503,
                'NexCore AI feature is not enabled for this organization.' => 403,
                'NexCore AI budget is not enabled for this organization.' => 403,
                'NexCore AI monthly request budget reached.' => 429,
                default => 502,
            };

            return response()->json(['status' => 'unavailable', 'message' => $exception->getMessage()], $status);
        }
    }
}
