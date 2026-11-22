# System-wide IDOR prevention standard

Status: mandatory engineering and release requirement for Nexora Group, NexISP, Loopza, and shared services.

## Security invariant

A record identifier is a locator, never an authorisation credential. Every read, write, delete, action, export, download, and background operation must independently establish that the authenticated principal may perform that exact action on that exact resource in its current tenant, country, organisation, and workflow state.

Changing an integer ID, UUID, public reference, slug, filename, parent ID, query parameter, or request-body field must never expand access.

## Required controls

1. **Scope before lookup.** Resolve user-owned resources through the authenticated user's relationship (for example, `$request->user()->traceCases()`) rather than an unrestricted `Model::findOrFail($id)`. Resolve tenant resources through the active, server-validated organisation/tenant scope. Resolve country-restricted resources through the current active country grants. Never trust a client-supplied tenant, owner, country, role, or organisation ID.
2. **Authorise every action.** Apply Laravel policies/gates or a dedicated domain authorisation service for view, list, create, update, delete, download, publish, approve, grant, revoke, and export. A successful list permission does not imply permission to open each row or its attachments. Re-check on every request; do not rely on hidden buttons or prior page access.
3. **Scope nested resources to their parent.** Fetch attachments, messages, notes, evidence, invoices, and child rows through the already-authorised parent relationship, e.g. `$case->documents()->whereKey($documentId)->firstOrFail()`. Never accept an independently supplied child ID without verifying the parent-child relationship.
4. **Enforce tenant and country isolation server-side.** All queries, joins, aggregates, search, pagination, bulk actions, exports, websocket subscriptions, and background jobs must preserve tenant and country boundaries. Tenant context must come from authenticated server-side membership, not request parameters.
5. **Protect sensitive files.** Keep private evidence outside the public web root and on private storage. Serve files only through an authenticated, authorised controller that checks both the parent record and the specific file. Use short-lived, single-purpose signed downloads only where necessary; never expose permanent public storage URLs. Set private/no-store and nosniff headers.
6. **Prevent mass-assignment escalation.** Never accept `owner_id`, `user_id`, `tenant_id`, `organisation_id`, approval state, role, country grant, publication state, or permission fields directly from untrusted input. Set these from trusted server-side context and validate writable fields with explicit allow-lists.
7. **Protect indirect paths.** Apply the same authorisation checks to APIs, web routes, mobile endpoints, GraphQL, search, reports, CSV/PDF exports, notifications, queue jobs, scheduled tasks, webhooks, admin tools, and internal service-to-service calls. Jobs must carry and revalidate a trusted actor/tenant scope or use a narrowly defined system identity.
8. **Use opaque IDs only as defence in depth.** UUIDs/public references reduce enumeration but do not replace authorisation. Numeric IDs are not inherently unsafe when every operation is correctly scoped.
9. **Fail closed.** Missing actor, tenant, country grant, parent relationship, or policy decision means deny. Prefer a consistent 404 for inaccessible object lookups where revealing existence would leak information; use 403 where the caller is authenticated and the denial is intentionally explicit.
10. **Audit sensitive denials and access.** Record actor, action, resource type/identifier, tenant/country scope, outcome, and correlation ID without logging secrets or sensitive evidence contents. Rate-limit enumeration-prone endpoints and alert on repeated cross-resource denials.

## Mandatory negative-test matrix

For every resource type and every endpoint that accepts or returns a resource identifier, tests must prove:

- User A cannot read, update, delete, download, approve, publish, or export User B's resource by substituting B's ID.
- A member of Tenant A cannot access Tenant B's resource, including through search, pagination, nested relationships, exports, and bulk endpoints.
- A reviewer/authority/investigator/volunteer cannot cross their assigned country or grant scope by changing a country, case, application, document, or parent ID.
- A user cannot fetch a child attachment by combining a valid child ID with a different parent ID.
- Revoked, expired, suspended, or inactive memberships/grants immediately lose access on the next request.
- Guessing UUIDs, public references, slugs, filenames, or sequential IDs does not bypass policy checks.
- Client-supplied owner/tenant/role/status/publication fields cannot reassign or elevate a record.
- Unauthenticated requests and users without the required role are denied.
- Queue jobs and export/download flows enforce the same scope as interactive requests.

Tests must include both a positive owner/authorised-role case and a negative cross-user/cross-tenant/cross-country case. A passing happy-path test alone is insufficient.

## Laravel implementation pattern

Prefer scoped binding and explicit policy checks:

```php
$case = $request->user()
    ->traceCases()
    ->whereKey($caseId)
    ->firstOrFail();

$this->authorize('view', $case);

$document = $case->documents()
    ->whereKey($documentId)
    ->firstOrFail();

$this->authorize('viewDocument', [$case, $document]);
```

For tenant-scoped resources, derive the active organisation from authenticated server-side membership and query through that organisation's relationship. For cross-country review, combine the active appointment check with a query constrained to the reviewer's current country grants before resolving the object. Revalidate those grants inside sensitive transactions where state can change concurrently.

## Release gate

No PR is ready to merge until its changed resource endpoints have documented ownership/tenant/country scopes, negative IDOR tests, nested-file tests where applicable, and passing CI. Security review must inspect all routes and indirect access paths, not only the screens changed by the PR. This standard is a baseline; it does not certify the existing application as fully audited or penetration-tested.
