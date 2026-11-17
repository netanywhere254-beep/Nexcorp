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
