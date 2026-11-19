# Nexora Group — Master Development & Implementation Directive

**Status:** Authoritative project brief  
**Repository:** `netanywhere254-beep/Nexcorp`  
**Primary stack:** Laravel 13, PHP, MySQL  
**Delivery target:** Integrated, tested, production-ready ecosystem

## Mission

Continue implementation in this existing repository. Inspect the current codebase, branches, pull requests, migrations, tests, and CI configuration before making changes. Preserve all working features and prior decisions. Do not replace the project with a disconnected demo, scaffold over existing work, or claim completion without verifiable tests.

Treat this document as the product and engineering directive. Where the existing repository contains a more specific approved implementation, reconcile it rather than blindly duplicating it. Record material decisions and update documentation as the system evolves.

## 1. Nexora Group — parent organisation

Build the corporate platform for Nexora Group, including:
- Public corporate website, company profile, subsidiaries, products, contact and support information.
- Secure internal management portal and executive overview.
- Organisation, department, team, country and subsidiary management.
- Governance, policy, approvals, audit history and operational reporting.
- Configurable modules, feature flags, maintenance mode, safe mode and service-status communications.
- A distinct IT Manager role with technical oversight of users, infrastructure, integrations, security events, deployments, backups and system health. IT Manager permissions must be explicit, least-privilege and auditable; do not silently grant unrestricted financial or business-owner powers.

## 2. NexISP

Implement NexISP as the commercial ISP operations and billing product, with NetPulse as its first tenant:
- Multi-tenant ISP onboarding, tenant isolation and configurable branding.
- Customer, staff, reseller, service-area and support management.
- Plans, subscriptions, invoices, receipts, payments, credits and reconciliation.
- MikroTik integration, RouterOS API support, provisioning and generated setup scripts.
- Router, NAS, hotspot, PPPoE, bandwidth/profile, session and usage management.
- Payment-provider abstraction, including investigation and implementation of supported Kenyan payment flows. Never store payment credentials or claim direct merchant-pouch settlement unless the provider officially supports and the integration verifies it.
- Tenant-scoped reporting, notifications, APIs, logs and permissions.

## 3. Loopza

Implement Loopza as the marketing and business-growth platform:
- Business accounts, subscriptions, campaign planning, creative assets and approvals.
- Integrations with Meta/Facebook, Instagram, Google, TikTok and YouTube, using official APIs and their current policies.
- Self-service and optional managed-campaign workflows.
- Budgets, spend tracking, reporting, billing and provider-token security.
- Country-aware configuration, currency, time zone, consent and data-retention rules.
- Domain referral/affiliate functionality only where provider terms permit it.
- Separate feature controls and maintenance states for each product module.

## 4. Loopza Trace & Find

Treat Trace & Find as a sensitive, internationally oriented discovery and case-management service, with strict safeguards:
- Distinguish Nexora Group-wide registration/reference numbers from Loopza Trace & Find case numbers. These are separate numbering systems, namespaces and records; never reuse or conflate them.
- Generate unique, non-guessable registration/case identifiers server-side and persist them transactionally in the database. Keep a durable record and prevent duplicates.
- Require OTP verification when a case is registered. OTP delivery must use configured providers, expiry, attempt limits, rate limits and audit events. Do not log or expose OTP secrets.
- General users may provide and verify their own phone numbers during registration/onboarding.
- Staff authentication numbers must be pre-registered and approved by the organisation before they can be used for staff OTP verification. Staff must not self-authorise a new number. Number changes require an authorised approval workflow and an audit trail.
- For location-bearing posts, support location authentication/confirmation followed by a selfie of the person posting, with clear consent, purpose limitation, secure storage and retention controls. Do not represent GPS or a selfie as conclusive proof of identity or physical presence.
- Provide case status, authorised evidence handling, secure uploads, access history and controlled notifications.
- Support families, verified authorities, approved volunteers and a distinct verified professional investigator role with separate permissions.
- Cross-border discovery must rely only on lawful public sources, authorised partnerships and jurisdiction-appropriate processes. Enforce country-specific access, disclosure, retention and escalation controls.
- Apply privacy-by-design: data minimisation, consent, safeguarding, role-scoped access, encryption, deletion/retention policies, abuse reporting and human review. Do not enable public exposure of sensitive case data or automated accusations/matches.

## 5. Identity, roles and security

Implement and test:
- Centralised authentication with secure sessions, password hashing, optional MFA and verified contact channels.
- Role-based access control and policy checks on every sensitive action and API endpoint.
- Separate permissions for owner/executive, organisation administrator, IT Manager, finance, operations, support, tenant administrator, family, investigator, authority and volunteer roles as applicable.
- Staff pre-approved-number policy and general-user self-registration policy described above.
- OTP expiry, single-use semantics, attempt throttling, resend cooldowns and provider failure handling.
- CSRF protection, validation, output encoding, SQL-injection prevention, secure headers, rate limits and safe error responses.
- Encrypted secrets and sensitive fields where appropriate; never commit credentials, API keys, private keys, production data or real OTPs.
- Immutable or tamper-evident audit records for privileged actions, approvals, access to sensitive cases and security events.
- Secure file type/size validation, malware-scanning integration points, private storage and signed/authorised downloads.
- Tenant isolation tests, IDOR checks, privilege-escalation tests and secure defaults.

## 6. AI capabilities

Provide a modular AI integration layer for:
- Group-wide internal knowledge and operational assistance.
- NexISP customer support and network troubleshooting assistance.
- Loopza campaign assistance and reporting.
- Trace & Find administrative assistance only within authorised, privacy-protective boundaries.
- Human escalation and review for sensitive, legal, safety, identity or case decisions.

Use provider abstraction, explicit configuration, usage limits, auditability and secret management. AI must not independently make high-impact determinations, disclose restricted case information, or bypass role permissions.

## 7. Architecture and implementation standards

- Laravel 13 is the primary application framework and MySQL is the primary relational database.
- Use modular boundaries, service classes, policies, form requests, events/jobs where appropriate, API versioning and documented contracts.
- Prefer maintainable, tested Laravel-native solutions. Do not introduce a second application stack without a documented, approved technical reason.
- Use migrations, factories and seeders that are safe and repeatable. Never run destructive production migrations without a reviewed backup and rollback plan.
- Build responsive, accessible interfaces with a polished, coherent visual identity. Use the established blue/cyan direction for Nexora branding where brand assets are available; preserve the separate identities of NexISP and Loopza.
- Keep configuration environment-driven. Include `.env.example` with placeholders only.
- Document setup for Windows and common Linux production environments, including PHP extensions, Composer, Node/build tooling, database setup, migrations, queues, scheduler, storage, mail/SMS providers and local development.
- Document APIs, roles, workflows, architecture, operations and troubleshooting.

## 8. Quality assurance and delivery gates

Before implementation:
1. Inspect repository structure, default branch, open PRs, recent commits, existing modules and current CI status.
2. Identify existing code for registration numbers, case OTP, staff-number approval, location/selfie verification and IT Manager permissions. Extend or repair it; do not create competing implementations.
3. Produce a concise gap list and implementation sequence.

For every change:
1. Work on a dedicated feature branch and keep commits focused.
2. Add or update migrations, validation, policies, tests and documentation together.
3. Run formatting/static checks where configured, targeted tests and the full relevant test suite.
4. Run the CI workflow and inspect actual results. Fix failures; never bypass, disable or weaken checks just to obtain a green result.
5. Review security, privacy, tenant isolation, backward compatibility and migration safety.
6. Open a pull request with a clear summary, risk notes, test evidence and deployment/migration notes.
7. Merge only when required checks pass and repository rules permit it. Never report a PR as merged or deployed until GitHub confirms it.

## 9. Deployment and operations

Prepare production deployment guidance covering:
- Supported PHP/runtime versions and required extensions.
- MySQL provisioning, least-privilege database accounts and migration strategy.
- Environment secrets, HTTPS, domain/DNS, mail and SMS configuration.
- Queue workers, scheduler, storage permissions and cache configuration.
- Monitoring, structured logs, alerting, uptime checks and incident response.
- Automated encrypted backups, restore drills, retention and disaster recovery.
- Dependency/security updates, rollback procedures and release checklist.
- Separate staging and production environments. Do not deploy to production or expose real user data as part of an unverified change.

## 10. Definition of done

A feature is complete only when its code exists in the repository, required migrations are present, authorisation and validation are enforced, automated tests cover normal and adverse cases, relevant CI checks pass, documentation is updated, and the pull request/release state is accurately reported.

Deliver actual working source code and verifiable repository changes—not just another prompt, mockup, plan or claim. Maintain a running implementation report listing completed work, open risks, failing checks and the next concrete task.
