# Nexora Group Ecosystem — Project Blueprint

**Status:** Dual-architecture foundation committed; NexISP and Loopza product implementation remains in progress.  
**Repository:** `netanywhere254-beep/Nexcorp`  
**Default branch:** `main`

## 1. Purpose

Nexcorp is the working repository for a group of related digital products. The repository will hold shared planning, technical documentation, and the foundations for each product. Product-specific code should remain clearly separated.

## 2. Company and product structure

- **Parent company — Nexora Group:** official umbrella organization for Nexora, NexISP, and Loopza.
- **NexISP:** a commercial ISP operations and billing platform. NetPulse WiFi is the intended first tenant and real-world pilot.
- **Loopza:** a SaaS platform for businesses to plan, manage, and amplify advertising across supported digital channels.

The products are related through shared ownership and reusable platform capabilities, but they are separate products with distinct users, interfaces, business rules, and roadmaps.

## 3. Current repository structure

```text
Nexcorp/
├── README.md
├── backend/
│   └── laravel/                 # Primary Laravel 13 application
├── backup/
│   └── node-postgres/
│       ├── api/                 # Standby NestJS API
│       └── web/                 # Standby Next.js app
├── frontend/
│   └── components/              # Product UI components
├── docs/                        # Requirements, architecture, schemas
└── infra/                       # Primary/standby Compose and env templates
```

- backend/laravel: canonical primary application and MySQL migration source.
- backup/node-postgres: isolated standby application foundation and PostgreSQL connection.
- frontend/: product interface components; they must be wired to authenticated backend endpoints before being treated as working features.
- docs/: product requirements, architecture decisions, database references, and delivery notes.
- infra/: separate Compose files, environment templates, and operational instructions.

The old root-level Compose proposals and Prisma/NestJS files remain reference material; they are not part of the active primary runtime.

## 4. Product scope

### NexISP — ISP management platform

Initial capability areas to design and deliver in stages:

1. Organizations, tenants, roles, and permissions.
2. ISP customers, service plans, subscriptions, and account lifecycle.
3. Billing, invoices, receipts, balances, and payment reconciliation.
4. Payment integrations, beginning with a feasibility assessment for Kenyan payment methods, including Pochi la Biashara where supported.
5. Network equipment and router management, starting with MikroTik and generated onboarding/configuration guidance.
6. Service provisioning, usage/status visibility, and customer notifications.
7. Support workflows and AI-assisted troubleshooting, with human oversight.
8. APIs, audit logs, security controls, backups, and operational monitoring.

**Pilot:** NetPulse WiFi. The platform must be designed so other ISPs can become separate tenants without seeing one another's data.

### Loopza — advertising and marketing SaaS

Initial capability areas to design and deliver in stages:

1. Business workspaces, users, roles, and subscriptions.
2. Campaign planning and advertising workflow management.
3. Channel integrations, subject to each provider's API access and approval: Meta platforms, Google, TikTok, and YouTube.
4. Self-service operation plus optional managed-advertising services.
5. Country-aware configuration, pricing, and user experiences.
6. Reporting, campaign status, notifications, and audit history.
7. Maintenance controls and safe handling of unavailable integrations.

Do not promise automatic publishing or campaign control until the relevant platform API permissions and technical requirements have been verified.

## 5. Shared engineering principles

- Keep tenant and customer data isolated.
- Use role-based access and least-privilege permissions.
- Protect credentials and secrets; never commit them to Git.
- Record important administrative and financial actions in audit logs.
- Design integrations behind clear interfaces so providers can be changed.
- Make maintenance and degraded-service states explicit.
- Include backups, recovery procedures, monitoring, and security testing.
- Prefer small, reviewable changes with clear commit messages.
- Document decisions rather than relying on chat history.

## 6. Selected technology arrangement

The project now has an explicit primary/standby decision:

- **Primary:** Laravel 13 (PHP 8.3+) modular monolith with MySQL 8.4. NexISP and Loopza are planned as isolated modules with server-side tenant and permission enforcement.
- **Standby alternative:** Next.js web + NestJS API + PostgreSQL 16, maintained in a separate source tree and database.
- **Primary UI direction:** Laravel Blade and lightweight JavaScript for the initial application. Product-specific frontend components may be integrated when the build pipeline and authenticated API contracts are ready.
- **External services:** adapters for M-Pesa, router management, messaging, and advertising providers, with provider permissions and regional availability checked before activation.

Laravel/MySQL is the canonical source of truth. The standby PostgreSQL system is not synchronized and does not have feature parity; switching requires tested data conversion, reconciliation, and a recovery drill. See [ARCHITECTURE_DECISION.md](ARCHITECTURE_DECISION.md).

## 7. Delivery roadmap

| Phase | Outcome |
|---|---|
| 0. Foundation | Confirm names, requirements, stack, repository conventions, and security baseline |
| 1. Product design | Detailed NexISP and Loopza requirements, user roles, workflows, and data boundaries |
| 2. Shared foundation | Authentication, authorization, organization model, audit logging, and configuration |
| 3. NexISP MVP | Core customer, package, subscription, billing, and initial router workflows |
| 4. Loopza MVP | Workspace, campaign planning, initial reporting, and first approved channel integration |
| 5. Integrations | Payment, network, messaging, and advertising-provider integrations |
| 6. Hardening | Automated tests, security review, backups, monitoring, and deployment runbooks |
| 7. Pilot and launch | Pilot with controlled users, fix issues, document operations, then expand gradually |

Phases may overlap only where dependencies and available resources allow. Do not treat a planned feature as delivered until it has been implemented and tested.

## 8. Immediate next tasks

- [x] Add authentication, registration, password reset, and verified account recovery.
- [x] Implement server-side organization membership, roles, permissions, and tenant isolation.
- [x] Add an authorized organization invitation/onboarding workflow.
- [ ] Add NexISP customer, plan, subscription, invoice, payment, and provisioning migrations.
- [ ] Add Loopza workspace, channel connection, campaign, approval, and reporting migrations.
- [ ] Add automated tests for financial calculations, tenant boundaries, and privileged actions.
- [ ] Implement tested backup/restore and cross-database export/import procedures before treating the standby as disaster recovery.
- [ ] Add production TLS, monitoring, rate limits, secret management, and rollback procedures.

## 9. Naming and decision notes

- **Nexcorp** is the current GitHub repository name; it does not by itself establish the legal parent-company name.
- **Nexora Group** is the confirmed official parent-company name.
- **NexISP** and **Loopza** are the current product names.
- The GitHub repository remains named `Nexcorp`; this does not change the official corporate brand.

## 10. Definition of done

A feature is not complete merely because files exist. It must have an agreed requirement, implementation, relevant automated or manual tests, security considerations, documentation, and a verified result in the intended environment.


## 11. Nexora parent-company management scope

Nexora Group is the confirmed parent-company name in this blueprint. Its corporate layer is intended to provide oversight of subsidiaries and shared executive reporting; it is not a replacement for the operational workspaces inside NexISP or Loopza.

The proposed corporate data model is documented separately in [NEXORA_PRISMA_SCHEMA_REFERENCE.prisma](NEXORA_PRISMA_SCHEMA_REFERENCE.prisma). It covers executive accounts and roles, subsidiary records, subsidiary revenue summaries with explicit currency, and corporate reports linked to their author and optionally to a subsidiary.

The corporate layer should provide portfolio-level visibility while preserving product-level access boundaries. Executive roles must be enforced server-side. Finance users should receive only the financial permissions required for their work, and report access should be auditable.

This schema is a reference only. It does not establish a deployed Nexora admin portal, finance ledger, or live subsidiary integrations. Revenue summaries must eventually be calculated from reconciled financial records rather than treated as the accounting ledger itself.

## 12. Alternative deployment proposal: three independent services

A supplied Compose draft proposes three separately deployed applications: Nexora, NexISP, and Loopza. The repository records an improved version at [docker-compose.nexora-multiservice-reference.yml](../infra/docker-compose.nexora-multiservice-reference.yml), with a placeholder-only environment template at [infra/.env.nexora-stack.example](../infra/.env.nexora-stack.example).

The reference defines one API and one isolated PostgreSQL database per product/service. This could support independent deployment and scaling, but it also introduces separate authentication/authorization boundaries, database operations, backups, monitoring, deployments, and service-to-service API contracts. Nexora portfolio reporting would need authenticated APIs or a governed integration layer; it should not read directly from NexISP or Loopza databases.

**The three-service architecture has not been selected as the primary or standby.** The approved arrangement is Laravel + MySQL as primary and the separate Next.js + NestJS + PostgreSQL foundation as standby, as documented in section 15. The three-service Compose file remains an optional reference only. Its expected `nexora/`, `nexisp/`, and `loopza/` build directories and Dockerfiles do not yet exist in the repository, so it is not currently buildable or deployable.

Before adopting this design, explicitly decide:
1. Whether each name represents an independently deployed service, a product, or a legal subsidiary.
2. Whether identity is centralized in Nexora or each service owns its user accounts.
3. Which APIs and authorization rules allow Nexora to obtain portfolio reports.
4. Who owns migrations, backups, monitoring, secrets, incident response, and releases for each service.
5. Whether the additional infrastructure and operational cost is justified for the first release.

## 13. Continuous integration baseline

A GitHub Actions workflow is stored at [../.github/workflows/deploy.yml](../.github/workflows/deploy.yml). It now installs dependencies and tests the primary Laravel application, tests the standby NestJS API, builds the standby Next.js application, validates the retained Prisma schema references, validates both active Compose configurations, and builds both active Docker stacks. It also validates the retained historical Compose references and checks the Linux installer shell syntax.

This is build-and-test CI, not production deployment. A green run would verify the checked-in foundations build and pass their current tests; it would not certify business feature completeness, production security, disaster recovery, or deployment. Add a protected deployment job only after a deployment target, environment secrets, approvals, health checks, backups, and rollback procedure are configured.

## 14. Historical single-backend deployment proposal

The earlier supplied root-level Compose proposal is retained at [docker-compose.master-ecosystem-reference.yml](../infra/docker-compose.master-ecosystem-reference.yml), with [infra/.env.master-ecosystem.example](../infra/.env.master-ecosystem.example), for historical reference. It expected root-level Dockerfiles and application files that were not present.

The approved standby alternative is now maintained separately under [backup/node-postgres](../backup/node-postgres), with its own Next.js web application, NestJS API, PostgreSQL service, Dockerfiles, and environment template. Use the dedicated [docker-compose.backup.yml](../infra/docker-compose.backup.yml) rather than the historical master-ecosystem reference.

The standby remains a starter foundation. It does not have feature parity with the primary Laravel application and does not synchronize production data. See section 15 and [ARCHITECTURE_DECISION.md](ARCHITECTURE_DECISION.md) for the approved arrangement and cutover requirements.


## 15. Approved dual-architecture arrangement

The project will retain two isolated application foundations:

- **Primary:** Laravel modular monolith + MySQL in `backend/laravel`. This remains the canonical implementation path and source of truth for new Nexora, NexISP, and Loopza business features.
- **Standby alternative:** Next.js web + NestJS API + PostgreSQL in `backup/node-postgres`. This is maintained separately as an alternative technical foundation.

The corresponding Compose files and environment templates are `infra/docker-compose.primary.yml` / `infra/.env.primary.example` and `infra/docker-compose.backup.yml` / `infra/.env.backup.example`. Their ports, networks, secrets, and database volumes are separate.

This decision does not mean automatic failover or database replication. MySQL and PostgreSQL require a designed and tested migration/reconciliation process. The backup application currently has only a starter web screen and API health/readiness endpoints; it does not have feature parity or production business data. See [ARCHITECTURE_DECISION.md](ARCHITECTURE_DECISION.md) for setup commands and the cutover requirements.

## 16. Configurable inactive-account lifecycle and permanent deletion

Account cleanup must be configurable independently for each customer tenant/client and product. Permanent deletion is an optional policy, not a mandatory platform-wide behavior.

- **Default policy:** notify an inactive account, suspend it after the configured inactivity threshold, and allow reactivation during a configured grace period. Suspension is reversible and does not erase account history.
- **Optional permanent-deletion switch:** a tenant administrator may enable or disable permanent deletion for that tenant, subject to the platform's legal and contractual controls. It must be off by default.
- **Policy configuration:** inactivity threshold, notification schedule, suspension behavior, grace period, final deletion delay, and exempt account categories must be configurable within platform limits.
- **Notice and approval:** issue advance notices through available verified contact channels, record delivery attempts, and provide an administrator review/approval step before irreversible deletion. A tenant must not be able to bypass platform-level legal holds or retention requirements.
- **Protected records:** invoices, receipts, payment transactions, tax/accounting records, disputes, security/audit events, and other records subject to legal or contractual retention must be retained or pseudonymized as appropriate. Deleting a login/account must not silently cascade-delete financial history.
- **Recovery and evidence:** preserve an auditable lifecycle history, record who enabled the policy and approved deletion, and maintain a documented recovery/export window before irreversible purge where technically and legally permitted.
- **Safety controls:** exclude active subscriptions, unpaid balances, unresolved disputes, legal holds, privileged owner accounts, and accounts required for operational continuity until explicitly resolved.
- **Enforcement:** enforce policy server-side with scheduled jobs, idempotent processing, tenant-scoped queries, and tests for tenant isolation, warnings, grace-period reactivation, approval, exclusions, and retention. UI switches alone are not security controls.

This policy is a requirement, not yet an implemented or legally validated deletion service. Retention periods and deletion rules must be reviewed for the countries and industries in which each tenant operates before enabling permanent deletion.


## 17. Public registration and privileged-role boundary

Public registration creates a basic user account only. It must not accept or honor client-supplied role, is_admin, organization_id, organization membership, account status, executive role, or permission fields. The server assigns the account's safe default status and stores only explicitly validated identity fields.

A newly registered account receives no organization membership and no product or group-administrator privileges. Organization access is granted only through a separate, authenticated and authorized invitation/onboarding workflow. Role changes, especially owner, administrator, executive, finance, and other privileged assignments, must be performed by an already authorized actor, checked against the target organization and role policy, and recorded in the audit log.

Registration must use strong password validation, unique normalized email addresses, request throttling, safe response serialization, and security tests that submit forged privilege fields. Registration must not automatically authenticate the new account until the product's email-verification and account-activation policy is implemented. The initial implementation creates the account and requires a separate login; email verification remains a production hardening task before public launch.
