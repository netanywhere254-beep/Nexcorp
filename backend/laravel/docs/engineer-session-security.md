# Laravel engineer-session persistence

This layer adds durable, tenant-scoped storage for the NetPulse engineer console session lifecycle.

## What is implemented

- Sessions are scoped to one organization and one authenticated user.
- A 256-bit random bearer token is returned once at creation; only its SHA-256 hash is stored.
- An actor can have only one active engineer session per organization.
- A separate engineer access grant is required; ordinary tenant owner/admin membership is not sufficient. Grants require distinct grantor and approver identities, a current validity window, and a non-empty scope document.
- Idle expiry is renewed on activity, but absolute expiry is never extended.
- Account, organization, and membership status are rechecked when a session is touched.
- Expiry, revocation, start, activity, and operator deactivation are written to the existing audit_logs table in the same database transaction as the session change.
- The GOLD MODE DEACTIVATE phrase is an explicit close command, not a password or authentication factor.

## Deliberately not exposed yet

No HTTP route or user-facing control is registered by this change. The service is a persistence primitive, not a complete authorization boundary. It must not be called from a public endpoint until all of the following are implemented and tested:

1. Authenticate the engineer using the platform's normal account session.
2. Require verified account status and step-up MFA for every new privileged session.
3. Create a dedicated, server-managed engineer access grant with two-person approval (grantor and approver must be different accounts), an expiry, and explicit device/permission scope. Tenant membership, even an owner/admin role, is not by itself engineer authorization.
4. Resolve the active organization from server-side session context; never trust a client-supplied organization ID.
5. Authorize every device operation against the engineer assignment, organization, device, and approved engagement.
6. Enforce the grant's device IDs and permission scope on every operation; a valid session must never imply unrestricted access to every router.
7. Keep the bearer token out of URLs, logs, analytics, browser local storage, and audit metadata. Return it only over TLS and store it in a protected client session.
8. Add CSRF protection, throttling, replay protection, emergency revocation, and security monitoring.
9. Ensure the Go agent and Laravel control plane share a documented, authenticated session-validation protocol before either can authorize router changes.

## Operational notes

Run the Laravel migration before enabling any integration: php artisan migrate

The service defaults to a 10-minute idle timeout and a 60-minute absolute lifetime. The configured idle timeout must be at least 30 seconds; the absolute lifetime cannot be shorter than idle timeout or exceed four hours. The current implementation does not restore active engineer sessions after process restarts by itself; the database is the source of truth and all future request middleware must revalidate the token against it.

No firmware write, router reboot, credential extraction, or device configuration action is exposed by this persistence layer.
