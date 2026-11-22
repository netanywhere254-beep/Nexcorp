# Nexora Group — Change and Replacement Register

Last updated: 2026-11-22
Working branch: `implementation/nexisp-bandwidth-plans`
Pull request: pending

This register distinguishes a committed source update from a verified build or working production feature.

## Replacement register

| ID | Previous file / implementation | Current replacement | Status | Verification |
|---|---|---|---|---|
| CHG-001 | Laravel Tinker dependency constraint | Updated Laravel dependency manifest | VERIFIED — PR #1 | Composer, migrations and PHPUnit passed in CI #131. |
| CHG-002 | Express API | NestJS TypeScript API reference foundation | VERIFIED — PR #1 | NestJS build and tests passed in CI #131. |
| CHG-004 | Earlier parent branding | Nexora Group branding | UPDATED — PR #1 | Source updated; live visual validation pending. |
| CHG-007 | Organization membership without central authorization | Active-organization middleware and policy foundation | VERIFIED — PR #1 | Laravel authorization tests passed in CI #131. |
| CHG-012 | Public registration with privilege boundary | Account-only registration; no public admin assignment | UPDATED — PR #1 | Registration hardening committed; email verification remains a launch gate. |
| CHG-013 | Governance schema without approval workflow | Transactional staged approval service and tests | UPDATED — PR #1 | No HTTP endpoint, CEO bootstrap, MFA or NestJS parity yet. |
| CHG-014 | No tenant-aware commerce tables | NexISP plans/subscriptions, Loopza products/orders, payment transaction records | UPDATED — PR #4 | Schema and models committed; payment verification and fulfillment remain outstanding. |
| CHG-015 | AI only referenced in planning documents | NexCore AI provider adapter, authenticated tenant-scoped gateway, usage metering, feature allow-list, tenant monthly budgets, and service tests | IMPLEMENTED FOUNDATION — CI pending | Added /ai/assist, provider configuration, ai_usage_logs, tenant budget policy table, locked monthly request reservations, output-token ceilings, service tests, and endpoint authentication/tenant-context security tests. Provider and tenant budgets are disabled by default. No UI, RAG, autonomous agents, orchestration, provider cost reconciliation, per-user quotas, or NestJS parity yet. |
| CHG-016 | NexISP plan schema without admin API | Tenant-scoped NexISP bandwidth plan management foundation | VERIFIED — CI run 44993942151 | Added /nexisp/plans list/create/show/update endpoints, manageNetwork authorization, per-tenant code uniqueness, validation, audit events, and feature tests. No subscriptions, invoices, payments, provisioning, or UI yet. |

## Architecture status

- Primary: Laravel 13 + MySQL.
- Standby: Next.js + NestJS + PostgreSQL. It is not yet a synchronized replica or automatic failover system.
- NexCore AI is initially implemented in the Laravel primary platform only. The NestJS standby must implement the same contract before AI failover can be claimed.
- Payment, product fulfillment, production deployment, backup restoration, and failover testing remain outstanding.

## Sign-off convention

- UPDATED / IMPLEMENTED FOUNDATION means source code is committed to a review branch.
- VERIFIED means relevant automated checks or manual tests passed and a run/result is recorded.
- DEPLOYED means the change is running in the intended environment and has been checked there.
- Do not use one status as a substitute for another.

## Archive policy

Superseded source snapshots are kept under archive/replaced-code/YYYY-MM-DD/. Never overwrite historical snapshots; create a new dated subfolder for each subsequent replacement.
