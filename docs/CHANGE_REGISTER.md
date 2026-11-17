# Nexora Group — Change and Replacement Register

Last updated: 2026-11-17  
Working branch: `implementation/nexora-group-audit`  
Pull request: [#1](https://github.com/netanywhere254-beep/Nexcorp/pull/1)

This register distinguishes a committed source update from a verified build or working production feature.

## Replacement register

| ID | Previous file / implementation | Current replacement | Status | Verification |
|---|---|---|---|---|
| CHG-001 | `backend/laravel/composer.json` with Laravel Tinker dependency constraint | Updated Laravel dependency manifest on this branch | VERIFIED — committed in PR #1 | Composer dependency installation, SQLite migrations and PHPUnit passed in CI run #131. |
| CHG-002 | Express API: `backup/node-postgres/api/src/server.js` | NestJS TypeScript API under `backup/node-postgres/api/src/` | VERIFIED — committed in PR #1 | NestJS build and tests passed in CI run #131. |
| CHG-003 | Express package manifest: `backup/node-postgres/api/package.json` | NestJS package manifest | VERIFIED — committed in PR #1 | Included in successful NestJS build/test job #131. |
| CHG-004 | Enterprise Group parent branding and subsidiary references | Nexora Group branding in parent/subsidiary pages and documentation | UPDATED — committed in PR #1 | Source changes included in PR #1; visual/live deployment validation remains pending. |
| CHG-005 | Prior deployment and workflow configuration | Updated Docker/GitHub Actions configuration for the two foundations | VERIFIED — committed in PR #1 | Both Compose configurations validated and primary/standby Docker image builds passed in CI run #131. |
| CHG-006 | Earlier dual-architecture CI result | Recorded successful dual-architecture CI runs #113 and #131 | VERIFIED | [CI #113](https://github.com/netanywhere254-beep/Nexcorp/actions/runs/44106165728) and latest [CI #131](https://github.com/netanywhere254-beep/Nexcorp/actions/runs/44126276369): all five jobs passed in each run. |
| CHG-007 | Organization membership without centralized authorization rules | Active-organization middleware, organization role policy, explicit policy registration, and tenant authorization feature tests | VERIFIED — committed in PR #1 | Middleware verifies the signed-in active user, selected organization, active organization status, and active membership on each protected route. Policy defines owner/admin/manager/billing/support/member capabilities. Laravel CI job passed in run #131. |
| CHG-008 | Inactive-account cleanup without a tenant-selectable permanent deletion policy | Documented staged account lifecycle with reversible suspension by default and optional tenant-level permanent-deletion switch | REQUIREMENT RECORDED — implementation pending | Added section 16 to `docs/PROJECT_BLUEPRINT.md`. Permanent deletion is off by default and subject to notice, approval, legal holds, retention, exemptions, audit history, and safety checks. |
| CHG-009 | Tenant middleware and role policy tested only through isolated test routes | Registered `organization.active` middleware alias; added `/auth/organization/context` endpoint returning server-verified organization context and policy-derived permissions; expanded feature tests | VERIFIED — committed in PR #1 | Previous versions of the changed bootstrap, routes, and test files were copied to the dated code archive before replacement. Laravel CI job passed in run #131. |
| CHG-010 | Older tenant-route implementation on `feature/tenant-route-enforcement` | Superseded by the newer organization context endpoint and authorization implementation on `implementation/nexora-group-audit` | DO NOT MERGE — stale branch | PR #3 is 15 commits behind and diverged from the current implementation branch. Its older route/controller must not overwrite the newer `/auth/organization/context` implementation. Keep PR #3 open for review until its disposition is decided. |

## Architecture status

- **Primary:** Laravel 13 + MySQL.
- **Standby:** Next.js + NestJS + PostgreSQL (Redis where configured).
- The standby is a separate technical foundation, not yet a synchronized replica or automatic failover system.
- Commercial product workflows, production deployment, database replication/conversion, restoration and failover testing remain outstanding.

## Sign-off convention

- **UPDATED** means the replacement source has been committed to the implementation branch.
- **VERIFIED** means the relevant automated checks or manual tests passed and a run/result is recorded.
- **DEPLOYED** means the change is running in the intended environment and has been checked there.
- Do not use one status as a substitute for another.

## Archive policy

Superseded source snapshots are kept under `archive/replaced-code/YYYY-MM-DD/`. Never overwrite historical snapshots; create a new dated subfolder for each subsequent replacement. See [archive README](../archive/replaced-code/2026-11-17/README.md).
