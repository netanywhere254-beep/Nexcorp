# Nexora primary application — Laravel + MySQL

This directory is the primary application foundation. It contains a minimal Laravel 13 application, a MySQL-ready Docker image, a health endpoint, and the first shared organization/user/audit migrations.

## Local development

Requirements: PHP 8.3+, Composer 2, and MySQL 8.

1. Copy the .env.example file to .env.
2. Set a unique APP_KEY by running php artisan key:generate.
3. Set your local MySQL connection values in .env.
4. Run composer install.
5. Run php artisan migrate.
6. Start the server with php artisan serve.

The application root is this directory. Do not run Composer from the repository root.

## Scope

This is a bootable application foundation, not the finished NexISP or Loopza product. Authentication screens, roles/permissions, tenant policies, ISP billing, router provisioning, Loopza campaign workflows, and payment integrations still need implementation and tests.

The Docker entrypoint generates a process-local APP_KEY if none is supplied. For persistent environments, set and retain a stable secret in the environment manager. Migrations are deliberately not run automatically on container startup.

## Shared identity foundation (initial)

The web session endpoints are available under `/auth`:

- `POST /auth/login` — validates credentials, rate-limits failed attempts, and regenerates the session ID.
- `GET /auth/me` — returns the authenticated user's basic profile and organization memberships.
- `POST /auth/organization` — accepts `organization_id`, verifies membership server-side, and sets the active organization in the session.
- `POST /auth/logout` — logs out, invalidates the session, and rotates the CSRF token.

These routes use Laravel's web middleware and cookie session guard; clients must obtain and send the Laravel CSRF cookie/token according to the deployment's same-origin or configured CORS/CSRF setup. Do not expose session cookies to JavaScript. Production must use HTTPS, secure cookies, an explicit trusted-origin policy, and a persistent shared session store.

This is the first identity slice, not the complete account lifecycle. Registration, email verification, password reset, MFA, device/session management, role-policy enforcement on every business endpoint, and tenant-scoped data services remain to be implemented and tested before production use. Organization selection alone does not authorize access: each future data query and mutation must independently enforce membership, role, and organization scope.
