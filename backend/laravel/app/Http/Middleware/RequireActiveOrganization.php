<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->status === 'active', 403, 'An active account is required.');

        $organizationId = $request->session()->get('active_organization_id');
        abort_unless($organizationId, 409, 'Select an organization before accessing this resource.');

        $membership = $user->organizations()
            ->wherePivot('status', 'active')
            ->where('organizations.status', 'active')
            ->whereKey($organizationId)
            ->first();

        abort_unless($membership, 403, 'You do not have active access to the selected organization.');

        // Downstream controllers must use this server-verified model, not a
        // client-supplied organization_id, as their tenant context.
        $request->attributes->set('activeOrganization', $membership);

        return $next($request);
    }
}
