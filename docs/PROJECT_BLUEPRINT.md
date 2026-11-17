# Nexcorp Project Blueprint

**Status:** Initial planning document  
**Repository:** `netanywhere254-beep/Nexcorp`  
**Default branch:** `main`

## 1. Purpose

Nexcorp is the working repository for a group of related digital products. The repository will hold shared planning, technical documentation, and the foundations for each product. Product-specific code should remain clearly separated.

## 2. Company and product structure

- **Parent company — Nexora (working name):** intended umbrella organization for the products. Confirm the final legal and public-facing name before launch.
- **NexISP:** a commercial ISP operations and billing platform. NetPulse WiFi is the intended first tenant and real-world pilot.
- **Loopza:** a SaaS platform for businesses to plan, manage, and amplify advertising across supported digital channels.

The products are related through shared ownership and reusable platform capabilities, but they are separate products with distinct users, interfaces, business rules, and roadmaps.

## 3. Initial repository structure

```text
Nexcorp/
├── README.md
├── docs/
│   ├── .gitkeep
│   └── PROJECT_BLUEPRINT.md
├── backend/
│   └── .gitkeep
├── frontend/
│   └── .gitkeep
└── infra/
    └── .gitkeep
```

- `docs/`: product requirements, architecture, decisions, setup guides, and delivery plans.
- `backend/`: server-side applications and APIs, organized by product or service.
- `frontend/`: web interfaces and shared design-system components.
- `infra/`: deployment, environments, automation, monitoring, and operational configuration.

Git does not retain empty directories, so each planned directory currently contains a `.gitkeep` placeholder.

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

## 6. Initial technology direction

The proposed starting stack is:
- **Backend:** Laravel (PHP), organized as a modular monolith.
- **Database:** MySQL.
- **Frontend:** Laravel Blade templates with lightweight JavaScript initially.
- **Payments and external services:** adapter/service interfaces so providers can be added or replaced without coupling core billing logic to one vendor.

This is the working direction, not yet a deployed application or a completed technical decision record. Confirm compatible versions, hosting, authentication, queues, and deployment approach before implementation. Avoid infrastructure that creates unavoidable recurring costs before the project has a working budget.

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

- [ ] Confirm the parent-company name and public product naming.
- [ ] Write separate detailed requirements for NexISP and Loopza.
- [ ] Decide whether the products will share one backend or use separately deployable services.
- [ ] Compare and select the initial technology stack.
- [ ] Define development, testing, and production environments.
- [ ] Create a prioritized MVP backlog with acceptance criteria.

## 9. Naming and decision notes

- **Nexcorp** is the current GitHub repository name; it does not by itself establish the legal parent-company name.
- **Nexora** is recorded as a working parent-company name, pending confirmation.
- **NexISP** and **Loopza** are the current product names.
- Do not rename the repository or change product branding until the naming decision is confirmed.

## 10. Definition of done

A feature is not complete merely because files exist. It must have an agreed requirement, implementation, relevant automated or manual tests, security considerations, documentation, and a verified result in the intended environment.


## 11. Nexora parent-company management scope

Nexora is the working parent-company name in this blueprint. Its corporate layer is intended to provide oversight of subsidiaries and shared executive reporting; it is not a replacement for the operational workspaces inside NexISP or Loopza.

The proposed corporate data model is documented separately in [NEXORA_PRISMA_SCHEMA_REFERENCE.prisma](NEXORA_PRISMA_SCHEMA_REFERENCE.prisma). It covers executive accounts and roles, subsidiary records, subsidiary revenue summaries with explicit currency, and corporate reports linked to their author and optionally to a subsidiary.

The corporate layer should provide portfolio-level visibility while preserving product-level access boundaries. Executive roles must be enforced server-side. Finance users should receive only the financial permissions required for their work, and report access should be auditable.

This schema is a reference only. It does not establish a deployed Nexora admin portal, finance ledger, or live subsidiary integrations. Revenue summaries must eventually be calculated from reconciled financial records rather than treated as the accounting ledger itself.

## 12. Alternative deployment proposal: three independent services

A supplied Compose draft proposes three separately deployed applications: Nexora, NexISP, and Loopza. The repository records an improved version at [docker-compose.nexora-multiservice-reference.yml](../infra/docker-compose.nexora-multiservice-reference.yml), with a placeholder-only environment template at [infra/.env.nexora-stack.example](../infra/.env.nexora-stack.example).

The reference defines one API and one isolated PostgreSQL database per product/service. This could support independent deployment and scaling, but it also introduces separate authentication/authorization boundaries, database operations, backups, monitoring, deployments, and service-to-service API contracts. Nexora portfolio reporting would need authenticated APIs or a governed integration layer; it should not read directly from NexISP or Loopza databases.

**No architecture switch has been approved.** The current initial technology direction remains Laravel modular monolith + MySQL. The three-service Compose file is a proposal only. Its expected `nexora/`, `nexisp/`, and `loopza/` build directories and Dockerfiles do not yet exist in the repository, so it is not currently buildable or deployable.

Before adopting this design, explicitly decide:
1. Whether each name represents an independently deployed service, a product, or a legal subsidiary.
2. Whether identity is centralized in Nexora or each service owns its user accounts.
3. Which APIs and authorization rules allow Nexora to obtain portfolio reports.
4. Who owns migrations, backups, monitoring, secrets, incident response, and releases for each service.
5. Whether the additional infrastructure and operational cost is justified for the first release.

## 13. Continuous integration baseline

A GitHub Actions workflow is stored at [../.github/workflows/deploy.yml](../.github/workflows/deploy.yml). It currently performs configuration validation only: all three Prisma reference schemas, the optional multi-service Compose model, and the Linux installer shell syntax.

The workflow deliberately does not run npm installation/tests, database migrations, or Docker image builds. There is not yet a root or per-service package manifest and lockfile, a checked-in production migration set, a working application test suite, or the Nexora/NexISP/Loopza Docker build contexts and Dockerfiles. A CI check passing therefore validates the reference configuration only; it does not mean an application has compiled, tests have passed, a database has migrated, or services have deployed.

Extend the workflow with real application-specific build and test jobs once those projects exist. Add a production deployment job only after selecting a deployment host, configuring protected GitHub environments and secrets, requiring appropriate approvals, and implementing health checks and rollback.

## 14. Alternative single-backend deployment proposal

A supplied Docker Compose design proposes one backend API, one Next.js frontend, and one PostgreSQL 15 database. Its hardened configuration reference is [docker-compose.master-ecosystem-reference.yml](../infra/docker-compose.master-ecosystem-reference.yml), with [infra/.env.master-ecosystem.example](../infra/.env.master-ecosystem.example) as a placeholder-only environment template.

This is a different option from the separate Nexora/NexISP/Loopza service proposal in section 12. It also conflicts with the current initial Laravel modular monolith + MySQL direction. No architecture switch has been approved.

The Compose reference expects a root backend `Dockerfile` and a root `Dockerfile.frontend`, plus compatible backend and Next.js application code. Those build files and complete applications are not currently present, so the reference is not yet buildable or deployable. The frontend `NEXT_PUBLIC_API_URL` is passed at build time; localhost is suitable only for local development. Production requires a real HTTPS endpoint or same-origin reverse proxy, protected secrets, database backups, migrations, health checks, monitoring, and a rollback plan.

Before using this alternative, decide whether the project should be a single Node/Next.js + PostgreSQL application, three independently deployed services, or the existing Laravel + MySQL modular monolith. Do not mix the three approaches into one deployment without a reviewed architecture and migration plan.
