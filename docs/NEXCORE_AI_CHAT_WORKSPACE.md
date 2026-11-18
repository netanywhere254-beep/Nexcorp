# NexCore AI chat workspace

Status: initial authenticated browser interface implemented; not deployed.

## Access and request flow

- Open `/ai` after signing in and selecting an active organization.
- The page is protected by Laravel session authentication and the `organization.active` middleware.
- Chat requests are sent to the existing `POST /ai/assist` endpoint with the selected allow-listed feature.
- The server derives the organization from the authenticated session. The browser does not submit an organization ID, provider key, model override, or permission claim.
- The browser sends the Laravel CSRF token and uses same-origin session cookies.
- Assistant output is inserted as text, not interpreted as HTML.

## Current limitations

- Messages and responses exist only in the current browser page; there is no server-side conversation history.
- The assistant provider remains disabled until deployment configuration supplies an approved API key and enables the feature.
- Organization-level AI budgets must be explicitly enabled before requests can be processed.
- Feature options in the interface are not a substitute for product-level entitlement checks. Before broad rollout, enforce server-side authorization mapping each organization/product membership to the permitted NexCore AI feature.
- No AI tools, router changes, payment actions, account changes, campaign publishing, or other privileged actions are exposed.

## Next controls before wider rollout

1. Add server-side product entitlement checks for each AI feature.
2. Add persistent conversations only after retention, deletion, export, and tenant ACL rules are implemented.
3. Add per-user quotas, abuse monitoring, provider cost reconciliation, and operational alerts.
4. Add specialist agent routing only through explicit permission scopes and human approvals.
